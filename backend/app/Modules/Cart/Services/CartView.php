<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Contracts\CartPresenter;
use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\DTOs\CartLine;
use App\Modules\Cart\DTOs\CartSnapshot;
use App\Modules\Cart\Enums\CartLineStatus;
use App\Modules\Cart\Models\Cart;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Customers\Contracts\CustomerDirectory;
use App\Modules\Inventory\Contracts\InventoryRecords;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Pricing\Contracts\CouponService;
use App\Modules\Pricing\DTOs\CouponEvaluation;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Shipping\Contracts\ShippingQuoteService;
use App\Modules\Shipping\DTOs\ShippingOption;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;

/**
 * Builds the API.md §2.5 `Cart` (always recalculated — ADR-007) and the pieces
 * Checkout reuses (CartPresenter).
 */
final class CartView implements CartPresenter
{
    public function __construct(
        private readonly CartCalculator $calculator,
        private readonly CartService $carts,
        private readonly CouponService $coupons,
        private readonly ShippingQuoteService $quotes,
        private readonly CustomerDirectory $customers,
        private readonly InventoryRecords $inventory,
        private readonly SettingsRepository $settings,
    ) {}

    /** Empty cart (no cart exists yet — GET without token). */
    public function empty(?int $customerId): array
    {
        return [
            'token' => null,
            'owner' => $customerId !== null ? 'customer' : 'guest',
            'items' => [],
            'items_count' => 0,
            'coupon' => null,
            'postal_code' => null,
            'totals' => ['subtotal_cents' => 0, 'discount_cents' => 0, 'shipping_cents' => null, 'shipping_discount_cents' => null, 'total_cents' => 0],
            'shipping_selection' => null,
            'total_weight_grams' => 0,
            'free_shipping_progress' => null,
            'price_list' => $this->priceList($customerId),
            'can_checkout' => false,
            'blocking_reasons' => ['cart_empty'],
            'has_price_changes' => false,
            'updated_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function build(Cart $cart, ?int $customerId, ?string $quoteId = null, ?string $optionId = null): array
    {
        $cart->unsetRelation('items');
        $cart->unsetRelation('coupon');
        $snapshot = $this->calculator->snapshot($cart, $customerId);
        $evaluation = $this->evaluateCoupon($snapshot);

        $discount = $evaluation?->valid ? $evaluation->discount->cents() : 0;
        $selection = null;
        $shipping = null;
        $shippingDiscount = null;
        if ($quoteId !== null && $optionId !== null) {
            [$selection, $option] = $this->selection($snapshot, $quoteId, $optionId);
            if ($option !== null) {
                $shippingDiscount = $option->couponDiscountCents();
                $shipping = $option->priceCents + $shippingDiscount;
            }
        }

        $blocking = $this->blockingReasons($snapshot, $evaluation);
        $this->availability = $this->availabilityFor($snapshot);

        return [
            'token' => $customerId === null ? $snapshot->token : null,
            'owner' => $cart->customer_id !== null ? 'customer' : 'guest',
            'items' => $this->items($snapshot),
            'items_count' => count($snapshot->lines),
            'coupon' => $this->coupon($snapshot, $evaluation),
            'postal_code' => $snapshot->postalCode,
            'totals' => [
                'subtotal_cents' => $snapshot->subtotal->cents(),
                'discount_cents' => $discount,
                'shipping_cents' => $shipping,
                'shipping_discount_cents' => $shippingDiscount,
                'total_cents' => max(0, $snapshot->subtotal->cents() - $discount) + ($shipping !== null ? $shipping - (int) $shippingDiscount : 0),
            ],
            'shipping_selection' => $selection,
            'total_weight_grams' => $snapshot->totalWeight->grams(),
            'free_shipping_progress' => $snapshot->isEmpty() ? null : $this->freeShippingProgress($snapshot->subtotal->cents() - $discount),
            'price_list' => $this->priceList($customerId),
            'can_checkout' => $blocking === [],
            'blocking_reasons' => $blocking,
            'has_price_changes' => $snapshot->hasPriceChanges(),
            'updated_at' => $snapshot->updatedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @var array<int, array{status: string, available_quantity: int|float|null}> per variant of the cart being built */
    private array $availability = [];

    public function cart(int $cartId, ?int $customerId): array
    {
        return $this->build(Cart::query()->findOrFail($cartId), $customerId);
    }

    public function configurationOf(?Quantity $quantity, ?int $widthMm, ?int $heightMm, ?int $pieces): array
    {
        $m = static fn (?int $mm): int|float|null => $mm !== null ? Quantity::fromMilli($mm)->toNumber() : null;

        return ['quantity' => $quantity?->toNumber(), 'width_m' => $m($widthMm), 'height_m' => $m($heightMm), 'pieces' => $pieces];
    }

    public function evaluateCoupon(CartSnapshot $snapshot): ?CouponEvaluation
    {
        if ($snapshot->couponCode === null) {
            return null;
        }

        return $this->coupons->evaluate($snapshot->couponCode, $this->carts->toCouponContext($snapshot, null));
    }

    /** @return list<string> */
    public function blockingReasons(CartSnapshot $snapshot, ?CouponEvaluation $evaluation): array
    {
        $reasons = [];
        if ($snapshot->isEmpty()) {
            $reasons[] = 'cart_empty';
        }
        $map = [
            CartLineStatus::Unavailable->value => 'item_unavailable',
            CartLineStatus::InsufficientStock->value => 'item_insufficient_stock',
            CartLineStatus::InvalidQuantity->value => 'item_invalid_quantity',
        ];
        foreach ($snapshot->lines as $line) {
            if (isset($map[$line->status->value]) && ! in_array($map[$line->status->value], $reasons, true)) {
                $reasons[] = $map[$line->status->value];
            }
        }
        if ($evaluation !== null && ! $evaluation->valid) {
            $reasons[] = 'coupon_invalid';
        }

        return $reasons;
    }

    public function items(CartSnapshot $cart): array
    {
        if ($this->availability === [] || array_diff($cart->variantIds(), array_keys($this->availability)) !== []) {
            $this->availability = $this->availabilityFor($cart);
        }

        return array_map(fn (CartLine $l): array => $this->item($l), $cart->lines);
    }

    public function coupon(CartSnapshot $cart, ?CouponEvaluation $evaluation): ?array
    {
        if ($cart->couponCode === null || $evaluation === null) {
            return null;
        }

        return [
            'code' => $evaluation->code ?? $cart->couponCode,
            'description' => $evaluation->description,
            'type' => $evaluation->type?->value,
            'valid' => $evaluation->valid,
            'reason_code' => $evaluation->valid ? null : $evaluation->reasonCode,
            'message' => $evaluation->valid ? null : $evaluation->message,
            'discount_cents' => $evaluation->valid ? $evaluation->discount->cents() : 0,
            'free_shipping' => $evaluation->valid && $evaluation->freeShipping,
        ];
    }

    /** @return array<string, mixed> API.md CartItem */
    private function item(CartLine $line): array
    {
        $v = $line->variant;
        $b = $line->billable;
        $p = $line->price;
        $unit = $v?->saleUnit;
        $isArea = $unit === SaleUnit::SquareMeter;

        return [
            'id' => $line->cartItemId,
            'variant_id' => $line->variantId,
            'product' => [
                'id' => $v?->productId,
                'slug' => $v?->productSlug,
                'name' => $v?->productName,
                'url_path' => $v?->urlPath(),
                'image' => null,
            ],
            'variant' => ['id' => $line->variantId, 'sku' => $v?->sku, 'name' => $v?->name, 'attributes' => (object) []],
            'sale_unit' => $unit?->value,
            'sale_unit_abbr' => $unit?->abbreviation(),
            'configuration' => self::configuration($line),
            'configuration_label' => self::configurationLabel($line),
            'billable_quantity' => $b?->billable->toNumber(),
            'stock_quantity' => $b?->stock->toNumber(),
            'piece_area_m2' => $isArea ? $b?->pieceArea?->toNumber() : null,
            'area_m2' => $isArea ? $b?->stock->toNumber() : null,
            'min_area_applied' => (bool) $b?->minimumAreaApplied,
            'unit_price_cents' => $p?->unitPrice->cents(),
            'base_unit_price_cents' => $p?->baseUnitPrice->cents(),
            'compare_at_cents' => $p?->compareAt()?->cents(),
            'price_source' => $p?->source->value,
            'price_source_label' => $p?->sourceLabel,
            'line_total_cents' => $p?->lineTotal->cents(),
            'weight_grams' => $line->weight->grams(),
            'status' => $line->status->value,
            'warnings' => $line->warnings,
            'rules' => $v !== null && $v->isSellable() ? self::rules($v) : null,
            'availability' => $this->availability[$line->variantId] ?? self::basicAvailability($line),
        ];
    }

    /** @return array{quantity: int|float|null, width_m: int|float|null, height_m: int|float|null, pieces: int|null} */
    public static function configuration(CartLine $line): array
    {
        $in = $line->input;

        return [
            'quantity' => $in->quantity?->toNumber(),
            'width_m' => $in->widthMm !== null ? Quantity::fromMilli($in->widthMm)->toNumber() : null,
            'height_m' => $in->heightMm !== null ? Quantity::fromMilli($in->heightMm)->toNumber() : null,
            'pieces' => $in->pieces,
        ];
    }

    /** "5 m" | "1,20 m × 2,50 m × 1 peça" | "2 rolos" */
    public static function configurationLabel(CartLine $line): string
    {
        $in = $line->input;
        if ($in->widthMm !== null && $in->heightMm !== null) {
            $pieces = (int) $in->pieces;

            return Quantity::fromMilli($in->widthMm)->format().' m × '.Quantity::fromMilli($in->heightMm)->format().' m × '
                .$pieces.($pieces === 1 ? ' peça' : ' peças');
        }
        $q = $in->quantity ?? Quantity::zero();
        $unit = $line->variant?->saleUnit;
        $number = $unit !== null && $unit->allowsFraction() ? rtrim(rtrim($q->format(3), '0'), ',') : $q->format(0);
        $n = $q->toNumber();

        return $number.' '.match ($unit) {
            SaleUnit::Roll => $n == 1 ? 'rolo' : 'rolos',
            SaleUnit::Box => $n == 1 ? 'caixa' : 'caixas',
            SaleUnit::Unit => 'un',
            SaleUnit::Kg => 'kg',
            default => 'm',
        };
    }

    /** @return array<string, mixed> API.md SaleUnitRules */
    public static function rules(VariantData $v): array
    {
        $m = static fn (?int $mm): int|float|null => $mm !== null ? Quantity::fromMilli($mm)->toNumber() : null;

        return [
            'sale_unit' => $v->saleUnit->value,
            'input' => match ($v->saleUnit) {
                SaleUnit::SquareMeter => 'dimensions',
                SaleUnit::LinearMeter, SaleUnit::Kg => 'decimal',
                default => 'integer',
            },
            'min_quantity' => $v->minQuantity->toNumber(),
            'max_quantity' => $v->maxQuantity?->toNumber(),
            'quantity_step' => $v->quantityStep->toNumber(),
            'fixed_width_m' => $m($v->fixedWidthMm),
            'min_width_m' => $m($v->minWidthMm),
            'max_width_m' => $m($v->maxWidthMm),
            'min_height_m' => $m($v->minHeightMm),
            'max_height_m' => $m($v->maxHeightMm),
            'min_billable_area_m2' => $v->minBillableArea?->toNumber(),
            'dimension_decimals' => 2,
            'max_pieces' => 1000,
        ];
    }

    /**
     * Estimated shipping in the summary (GET /cart?shipping_quote_id&shipping_option_id).
     * Invalid/expired is not an HTTP error: valid=false + issue_code.
     *
     * @return array{0: array<string, mixed>|null, 1: ShippingOption|null}
     */
    private function selection(CartSnapshot $snapshot, string $quoteId, string $optionId): array
    {
        $quote = $this->quotes->find($quoteId);
        if ($quote === null || $quote->cartId !== $snapshot->cartId
            || ($quote->customerId !== null && $quote->customerId !== $snapshot->customerId)) {
            return [null, null];
        }
        $option = $quote->option($optionId);
        if ($option === null) {
            return [null, null];
        }
        $issue = match (true) {
            $quote->isExpired() => 'shipping_quote_expired',
            $snapshot->postalCode !== null && $quote->destination->postalCode !== $snapshot->postalCode => 'shipping_postal_code_changed',
            default => null,
        };

        return [[
            'quote_id' => $quoteId,
            'option_id' => $optionId,
            'option' => $option->toPublicArray(),
            'valid' => $issue === null,
            'issue_code' => $issue,
        ], $issue === null ? $option : null];
    }

    /**
     * API.md Availability, same rule as the catalog: out_of_stock below the minimum
     * sellable quantity; low_stock at or below the threshold (variant override ??
     * inventory.default_low_stock_threshold), with the available quantity.
     *
     * @return array<int, array{status: string, available_quantity: int|float|null}>
     */
    public function availabilityFor(CartSnapshot $snapshot): array
    {
        $ids = $snapshot->variantIds();
        if ($ids === []) {
            return [];
        }
        $overrides = Inventory::query()->whereIn('variant_id', $ids)->pluck('low_stock_threshold', 'variant_id');
        $default = $this->inventory->defaultLowStockThreshold();
        $out = [];
        foreach ($snapshot->lines as $line) {
            $v = $line->variant;
            $available = $line->available;
            $threshold = $overrides[$line->variantId] ?? null;
            $threshold = $threshold instanceof Quantity ? $threshold : ($threshold !== null ? Quantity::fromNumeric((string) $threshold) : $default);
            $min = $v === null || $v->saleUnit === SaleUnit::SquareMeter ? Quantity::fromMilli(1) : $v->minQuantity;
            $out[$line->variantId] = match (true) {
                ! $available->isPositive() || $available->lessThan($min) => ['status' => 'out_of_stock', 'available_quantity' => null],
                $available->lessThanOrEqual($threshold) => ['status' => 'low_stock', 'available_quantity' => $available->toNumber()],
                default => ['status' => 'in_stock', 'available_quantity' => null],
            };
        }

        return $out;
    }

    /** @return array{status: string, available_quantity: null} */
    private static function basicAvailability(CartLine $line): array
    {
        return ['status' => $line->available->isPositive() ? 'in_stock' : 'out_of_stock', 'available_quantity' => null];
    }

    /**
     * Setting storefront.free_shipping_banner {enabled, threshold_cents, text}
     * compared with the products subtotal after the coupon.
     *
     * @return array{threshold_cents: int, remaining_cents: int, text: string}|null
     */
    public function freeShippingProgress(int $subtotalAfterDiscountCents): ?array
    {
        $banner = $this->settings->get(SettingKey::StorefrontFreeShippingBanner);
        if (! is_array($banner) || ! ($banner['enabled'] ?? false) || (int) ($banner['threshold_cents'] ?? 0) <= 0) {
            return null;
        }
        $threshold = (int) $banner['threshold_cents'];

        return [
            'threshold_cents' => $threshold,
            'remaining_cents' => max(0, $threshold - max(0, $subtotalAfterDiscountCents)),
            'text' => (string) ($banner['text'] ?? ''),
        ];
    }

    /** @return array{code: string, name: string}|null */
    private function priceList(?int $customerId): ?array
    {
        if ($customerId === null) {
            return null;
        }
        $id = $this->customers->pricingProfile($customerId)->priceListId;
        $list = $id !== null ? PriceList::query()->find($id) : null;

        return $list !== null ? ['code' => (string) $list->code, 'name' => (string) $list->name] : null;
    }
}
