<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\DTOs\CartLine;
use App\Modules\Cart\DTOs\CartSnapshot;
use App\Modules\Cart\Enums\CartLineStatus;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Contracts\CatalogQuery;
use App\Modules\Catalog\Contracts\SaleQuantityResolver;
use App\Modules\Catalog\DTOs\BillableQuantity;
use App\Modules\Catalog\DTOs\SaleInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Exceptions\InvalidSaleQuantity;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Pricing\DTOs\PriceContext;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use App\Shared\Domain\Weight;
use Carbon\CarbonImmutable;

/**
 * Recalculates a cart (ADR-007): quantity rules, prices (tiers by the variant total —
 * ADR-019), soft stock check and warnings. Never persists anything.
 */
final class CartCalculator
{
    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly SaleQuantityResolver $quantities,
        private readonly PriceResolver $prices,
        private readonly InventoryService $inventory,
    ) {}

    public function snapshot(Cart $cart, ?int $customerId): CartSnapshot
    {
        $cart->loadMissing(['items', 'coupon']);
        /** @var list<CartItem> $items */
        $items = $cart->items->all();

        return $this->calculate($cart, $items, $customerId);
    }

    /**
     * Calculates a snapshot for a hypothetical set of items (used to validate a
     * mutation before persisting it).
     *
     * @param  list<CartItem>  $items
     */
    public function calculate(Cart $cart, array $items, ?int $customerId): CartSnapshot
    {
        $variantIds = array_values(array_unique(array_map(static fn (CartItem $i): int => (int) $i->variant_id, $items)));
        $variants = $variantIds === [] ? [] : $this->catalog->variants($variantIds);

        // 1. quantity rules
        $resolved = [];
        foreach ($items as $index => $item) {
            $variant = $variants[$item->variant_id] ?? null;
            $input = self::inputOf($item);
            $entry = ['item' => $item, 'variant' => $variant, 'input' => $input, 'billable' => null,
                'status' => CartLineStatus::Ok, 'message' => null, 'suggestions' => []];

            if ($variant === null || ! $variant->isActive || ! $variant->productIsActive) {
                $entry['status'] = CartLineStatus::Unavailable;
                $entry['message'] = 'Produto indisponível.';
            } else {
                try {
                    $entry['billable'] = $this->quantities->resolve($variant, $input);
                } catch (InvalidSaleQuantity $e) {
                    $details = InvalidQuantityDetails::from($e);
                    $entry['status'] = CartLineStatus::InvalidQuantity;
                    $entry['message'] = $details['message'];
                    $entry['suggestions'] = $details['suggestions'];
                }
            }
            $resolved[$index] = $entry;
        }

        // 2. tier quantity = sum of the variant in the cart (ADR-019); stock = sum per variant
        $tierByVariant = [];
        $stockByVariant = [];
        foreach ($resolved as $entry) {
            if ($entry['billable'] instanceof BillableQuantity) {
                $id = (int) $entry['item']->variant_id;
                $tierByVariant[$id] = ($tierByVariant[$id] ?? Quantity::zero())->add($entry['billable']->billable);
                $stockByVariant[$id] = ($stockByVariant[$id] ?? Quantity::zero())->add($entry['billable']->stock);
            }
        }

        // 3. prices in batch
        $priceable = array_filter($resolved, static fn (array $e): bool => $e['billable'] instanceof BillableQuantity);
        $quotes = [];
        if ($priceable !== []) {
            $subjects = $this->catalog->pricingSubjects(array_keys($tierByVariant));
            $now = CarbonImmutable::now();
            $contexts = [];
            $keys = [];
            foreach ($priceable as $index => $entry) {
                $id = (int) $entry['item']->variant_id;
                $contexts[] = new PriceContext($subjects[$id], $entry['billable']->billable, $tierByVariant[$id], $customerId, $now);
                $keys[] = $index;
            }
            foreach ($this->prices->resolveMany($contexts) as $position => $quote) {
                $quotes[$keys[$position]] = $quote;
            }
        }

        // 4. soft stock check
        $available = $variantIds === [] ? [] : $this->inventory->availability($variantIds);

        $lines = [];
        $subtotal = Money::zero();
        $weight = Weight::zero();
        foreach ($resolved as $index => $entry) {
            /** @var CartItem $item */
            $item = $entry['item'];
            $id = (int) $item->variant_id;
            $avail = $available[$id] ?? Quantity::zero();
            $status = $entry['status'];
            $warnings = [];
            $quote = $quotes[$index] ?? null;

            if ($status === CartLineStatus::Ok && isset($stockByVariant[$id]) && $stockByVariant[$id]->greaterThan($avail)) {
                $status = CartLineStatus::InsufficientStock;
                $warnings[] = [
                    'code' => 'insufficient_stock',
                    'requested_quantity' => $stockByVariant[$id]->toNumber(),
                    'available_quantity' => Quantity::max($avail, Quantity::zero())->toNumber(),
                    'message' => $avail->isPositive()
                        ? 'Estoque insuficiente. Disponível: '.$avail->format().' '.$entry['variant']->saleUnit->abbreviation().'.'
                        : 'Produto sem estoque no momento.',
                ];
            }
            if ($status === CartLineStatus::Unavailable) {
                $warnings[] = ['code' => 'unavailable', 'message' => 'Produto indisponível.'];
            }
            if ($status === CartLineStatus::InvalidQuantity) {
                $warnings[] = ['code' => 'invalid_quantity', 'message' => (string) $entry['message'], 'suggestions' => $entry['suggestions']];
            }
            if ($quote !== null && $item->last_seen_unit_price_cents !== null
                && $item->last_seen_unit_price_cents !== $quote->unitPrice->cents()) {
                $warnings[] = [
                    'code' => 'price_changed',
                    'previous_unit_price_cents' => (int) $item->last_seen_unit_price_cents,
                    'current_unit_price_cents' => $quote->unitPrice->cents(),
                ];
            }
            $billable = $entry['billable'];
            if ($billable instanceof BillableQuantity && $billable->minimumAreaApplied) {
                $warnings[] = [
                    'code' => 'min_area_applied',
                    'area_m2' => $billable->stock->toNumber(),
                    'billable_area_m2' => $billable->billable->toNumber(),
                ];
            }

            $lineWeight = $billable instanceof BillableQuantity && $entry['variant'] instanceof VariantData
                ? self::lineWeight($entry['variant'], $billable)
                : Weight::zero();

            $line = new CartLine(
                cartItemId: (int) $item->id,
                variantId: $id,
                variant: $entry['variant'],
                input: $entry['input'],
                billable: $billable,
                price: $status->isPriceable() ? $quote : null,
                available: $avail,
                isAvailable: $status !== CartLineStatus::Unavailable,
                status: $status,
                warnings: $warnings,
                lastSeenUnitPriceCents: $item->last_seen_unit_price_cents !== null ? (int) $item->last_seen_unit_price_cents : null,
                weight: $lineWeight,
                issueMessage: $entry['message'] ?? ($status === CartLineStatus::InsufficientStock ? $warnings[0]['message'] : null),
                suggestions: $entry['suggestions'],
            );
            if ($line->isPriceable()) {
                $subtotal = $subtotal->add($quote->lineTotal);
                $weight = $weight->add($lineWeight);
            }
            $lines[] = $line;
        }

        return new CartSnapshot(
            cartId: (int) $cart->id,
            customerId: $customerId,
            lines: $lines,
            subtotal: $subtotal,
            couponCode: $cart->coupon?->code,
            totalWeight: $weight,
            hash: self::hash($items),
            token: (string) $cart->token,
            couponId: $cart->coupon_id !== null ? (int) $cart->coupon_id : null,
            postalCode: $cart->postal_code,
            updatedAt: $cart->updated_at !== null ? CarbonImmutable::parse($cart->updated_at) : null,
        );
    }

    public static function inputOf(CartItem $item): SaleInput
    {
        return new SaleInput(
            $item->quantity,
            $item->width_mm !== null ? (int) $item->width_mm : null,
            $item->height_mm !== null ? (int) $item->height_mm : null,
            $item->pieces !== null ? (int) $item->pieces : null,
        );
    }

    /** SHIPPING.md §2.3: ceil(weight × billable); KG: billable milli-kg are grams. */
    public static function lineWeight(VariantData $variant, BillableQuantity $billable): Weight
    {
        if ($variant->saleUnit === SaleUnit::Kg) {
            return Weight::fromGrams($billable->billable->milli());
        }

        return Weight::fromGrams($variant->weightGrams)->multiplyByQuantity($billable->billable);
    }

    /**
     * sha256 of what the customer chose (items + quantities + dimensions).
     *
     * @param  list<CartItem>  $items
     */
    public static function hash(array $items): string
    {
        $configs = array_map(static fn (CartItem $i): array => [
            'variant_id' => (int) $i->variant_id,
            'quantity_milli' => $i->quantity?->milli(),
            'width_mm' => $i->width_mm !== null ? (int) $i->width_mm : null,
            'height_mm' => $i->height_mm !== null ? (int) $i->height_mm : null,
            'pieces' => $i->pieces !== null ? (int) $i->pieces : null,
        ], $items);
        usort($configs, static fn (array $a, array $b): int => [$a['variant_id'], $a['width_mm'], $a['height_mm']] <=> [$b['variant_id'], $b['width_mm'], $b['height_mm']]);

        return hash('sha256', (string) json_encode($configs, JSON_UNESCAPED_SLASHES));
    }
}
