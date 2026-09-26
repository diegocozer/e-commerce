<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\DTOs\CartLine;
use App\Modules\Cart\DTOs\CartSnapshot;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Contracts\CatalogQuery;
use App\Modules\Catalog\Contracts\SaleQuantityResolver;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Exceptions\InvalidSaleQuantity;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Contracts\GuestCartMerger;
use App\Modules\Customers\DTOs\CartMergeReport;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Pricing\Contracts\CouponService;
use App\Modules\Pricing\DTOs\CouponContext;
use App\Modules\Pricing\DTOs\CouponLine;
use App\Modules\Shipping\DTOs\CartLineLogisticsInput;
use App\Shared\Domain\Money;
use App\Shared\Domain\PackageDimensions;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class EloquentCartService implements CartService, GuestCartMerger
{
    public function __construct(
        private readonly CartCalculator $calculator,
        private readonly CartLocator $carts,
        private readonly CatalogQuery $catalog,
        private readonly SaleQuantityResolver $quantities,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
    ) {}

    public function snapshot(int $cartId, ?int $customerId): CartSnapshot
    {
        return $this->calculator->snapshot(Cart::query()->findOrFail($cartId), $customerId);
    }

    public function activeCartIdForCustomer(int $customerId): ?int
    {
        $id = $this->carts->activeForCustomer($customerId)?->id;

        return $id !== null ? (int) $id : null;
    }

    public function toShippingLines(CartSnapshot $cart): array
    {
        $rollPackages = $this->rollPackages($cart);
        $lines = [];
        foreach ($cart->priceableLines() as $line) {
            $v = $line->variant;
            $b = $line->billable;
            if ($v === null || $b === null || $line->price === null) {
                continue;
            }
            $area = $v->saleUnit === SaleUnit::SquareMeter;
            $lines[] = new CartLineLogisticsInput(
                variantId: $v->id,
                saleUnit: $v->saleUnit,
                billable: $b->billable,
                widthMm: $area ? ($b->widthMm ?? $line->input->widthMm) : null,
                heightMm: $area ? ($b->heightMm ?? $line->input->heightMm) : null,
                pieces: $area ? ($b->pieces ?? $line->input->pieces) : null,
                weightGrams: $v->weightGrams,
                package: $v->package ?? ($rollPackages[$v->id] ?? null),
                unitsPerPackage: $v->unitsPerPackage,
                fixedWidthMm: $v->fixedWidthMm,
                pickupOnly: $v->pickupOnly,
                sku: $v->sku,
                lineTotalCents: $line->price->lineTotal->cents(),
            );
        }

        return $lines;
    }

    /**
     * Workaround (reported): Catalog's VariantData::$package is null when
     * package_length_cm is null, but rolls (LINEAR_METER/SQUARE_METER) only need the
     * diameter (width/height) — SHIPPING.md §2.3. Rebuild it read-only from the variant.
     *
     * @return array<int, PackageDimensions>
     */
    private function rollPackages(CartSnapshot $cart): array
    {
        $ids = [];
        foreach ($cart->lines as $line) {
            if ($line->variant !== null && $line->variant->package === null
                && in_array($line->variant->saleUnit, [SaleUnit::LinearMeter, SaleUnit::SquareMeter], true)) {
                $ids[] = $line->variantId;
            }
        }
        if ($ids === []) {
            return [];
        }
        $packages = [];
        foreach (ProductVariant::query()->whereKey($ids)->get(['id', 'package_width_cm', 'package_height_cm']) as $row) {
            if ($row->package_width_cm !== null && $row->package_height_cm !== null) {
                $w = (string) $row->package_width_cm;
                $packages[(int) $row->id] = PackageDimensions::fromCentimeters($w, $w, (string) $row->package_height_cm);
            }
        }

        return $packages;
    }

    public function itemConfigs(CartSnapshot $cart): array
    {
        return array_map(static fn (CartLine $l): array => [
            'variant_id' => $l->variantId,
            'quantity_milli' => $l->input->quantity?->milli(),
            'width_mm' => $l->input->widthMm,
            'height_mm' => $l->input->heightMm,
            'pieces' => $l->input->pieces,
        ], $cart->lines);
    }

    public function toCouponContext(CartSnapshot $cart, ?Money $shipping): CouponContext
    {
        $lines = [];
        foreach ($cart->priceableLines() as $line) {
            $lines[] = new CouponLine(
                $line->variantId,
                (int) $line->variant?->productId,
                $line->variant?->categoryIdsWithAncestors ?? [],
                $line->price->lineTotal,
            );
        }

        return new CouponContext($cart->customerId, $cart->subtotal, $lines, $shipping);
    }

    public function markConverted(int $cartId, int $orderId): void
    {
        $cart = Cart::query()->whereKey($cartId)->lockForUpdate()->firstOrFail();
        $cart->converted_at = now();
        $cart->converted_order_id = $orderId;
        $cart->save();
    }

    public function mergeGuestInto(string $guestToken, int $customerId): void
    {
        $this->mergeGuestCart($guestToken, $customerId);
    }

    public function merge(?string $guestCartToken, int $customerId): ?CartMergeReport
    {
        if ($guestCartToken === null || $guestCartToken === '') {
            return null;
        }
        try {
            return $this->mergeGuestCart($guestCartToken, $customerId);
        } catch (Throwable $e) {
            // Login must never fail because of the guest cart (GuestCartMerger contract).
            Log::warning('cart.merge.failed', ['customer_id' => $customerId, 'exception' => $e::class, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function mergeGuestCart(string $guestToken, int $customerId): ?CartMergeReport
    {
        return DB::transaction(function () use ($guestToken, $customerId): ?CartMergeReport {
            $guest = $this->carts->guestByToken($guestToken);
            if ($guest === null) {
                return null;
            }
            // Lock order: carts by id (DATABASE.md §4.2).
            $target = $this->carts->activeForCustomer($customerId);
            $ids = array_filter([(int) $guest->id, $target?->id !== null ? (int) $target->id : null]);
            sort($ids);
            Cart::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            [$target] = $target !== null ? [$target, false] : $this->carts->findOrCreate($customerId, null);
            $guest->load(['items', 'coupon']);
            $guestItems = $guest->items->all();

            $report = ['added' => 0, 'combined' => 0, 'adjustments' => [], 'dropped' => []];
            if ($guestItems !== []) {
                $variants = $this->catalog->variants(array_values(array_unique(array_map(static fn (CartItem $i): int => (int) $i->variant_id, $guestItems))));
                foreach ($guestItems as $item) {
                    $this->mergeItem($target, $item, $variants[$item->variant_id] ?? null, $report);
                }
            }

            $coupon = null;
            if ($guest->coupon_id !== null && $guest->coupon !== null) {
                $code = (string) $guest->coupon->code;
                if ($target->coupon_id !== null) {
                    $coupon = ['code' => $code, 'kept' => false, 'reason_code' => null];
                } else {
                    $target->unsetRelation('items');
                    $snapshot = $this->calculator->snapshot($target, $customerId);
                    $evaluation = $this->coupons->evaluate($code, $this->toCouponContext($snapshot, null));
                    if ($evaluation->valid) {
                        $target->coupon_id = $evaluation->couponId ?? $guest->coupon_id;
                    }
                    $coupon = ['code' => $code, 'kept' => $evaluation->valid, 'reason_code' => $evaluation->reasonCode];
                }
            }

            $guest->delete();
            $this->carts->touch($target);

            return new CartMergeReport(
                merged: $guestItems !== [],
                linesAdded: $report['added'],
                linesCombined: $report['combined'],
                adjustments: $report['adjustments'],
                dropped: $report['dropped'],
                coupon: $coupon,
            );
        });
    }

    /** @param array{added: int, combined: int, adjustments: list<array<string, mixed>>, dropped: list<array<string, mixed>>} $report */
    private function mergeItem(Cart $target, CartItem $item, ?VariantData $variant, array &$report): void
    {
        $label = static fn (string $reason) => [
            'variant_id' => (int) $item->variant_id,
            'sku' => $variant->sku ?? '',
            'product_name' => $variant->productName ?? '',
            'reason' => $reason,
        ];
        if ($variant === null || ! $variant->isSellable()) {
            $report['dropped'][] = $label('unavailable');

            return;
        }

        /** @var CartItem|null $existing */
        $existing = $target->items()->where('variant_id', $item->variant_id)
            ->where(fn ($q) => $item->width_mm === null ? $q->whereNull('width_mm') : $q->where('width_mm', $item->width_mm))
            ->where(fn ($q) => $item->height_mm === null ? $q->whereNull('height_mm') : $q->where('height_mm', $item->height_mm))
            ->first();

        $isArea = $variant->saleUnit === SaleUnit::SquareMeter;
        $unitsOf = static fn (CartItem $i): Quantity => $isArea ? Quantity::ofUnits((int) $i->pieces) : $i->quantity;

        if ($existing === null && $target->items()->count() >= CartItemGuard::MAX_LINES) {
            $report['dropped'][] = $label('line_limit');

            return;
        }

        $line = $existing ?? $item->replicate(['cart_id']);
        $previous = $existing !== null ? $unitsOf($existing)->add($unitsOf($item)) : $unitsOf($item);
        $wanted = $previous;
        $reason = null;

        // quantity rules (max / step) — clamp to the largest valid value
        if (! $this->isValid($variant, $line, $wanted)) {
            $clamped = $this->clampToRules($variant, $wanted);
            if ($clamped === null || ! $this->isValid($variant, $line, $clamped)) {
                if ($existing === null) {
                    $report['dropped'][] = $label('invalid_quantity');
                }

                return;
            }
            $wanted = $clamped;
            $reason = 'max_quantity';
        }

        // stock by the variant total (other lines of the variant stay as they are)
        $others = Quantity::zero();
        foreach ($target->items()->where('variant_id', $item->variant_id)->get() as $other) {
            if ($existing === null || $other->id !== $existing->id) {
                $others = $others->add($this->resolveStock($variant, $other) ?? Quantity::zero());
            }
        }
        $available = $this->inventory->availability([$variant->id])[$variant->id] ?? Quantity::zero();
        $stock = $this->resolveStock($variant, $this->withUnits($line, $wanted, $isArea));
        if ($stock !== null && $others->add($stock)->greaterThan($available)) {
            $fit = $this->fitToStock($variant, $line, $available->subtract($others), $isArea);
            if ($fit !== null && $fit->lessThan($wanted)) {
                $wanted = $fit;
                $reason = 'insufficient_stock';
            }
            // no valid quantity fits: keep it; the line shows `insufficient_stock` (RN-CAR-030)
        }

        $this->withUnits($line, $wanted, $isArea);
        if ($existing === null) {
            $line->cart_id = $target->id;
            $line->last_seen_unit_price_cents = $item->last_seen_unit_price_cents;
            $report['added']++;
        } else {
            $report['combined']++;
        }
        $line->save();

        if ($reason !== null) {
            $report['adjustments'][] = [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'product_name' => $variant->productName,
                'previous_quantity' => $previous->toNumber(),
                'quantity' => $wanted->toNumber(),
                'reason' => $reason,
            ];
        }
    }

    private function withUnits(CartItem $line, Quantity $units, bool $isArea): CartItem
    {
        if ($isArea) {
            $line->pieces = intdiv($units->milli(), Quantity::SCALE);
        } else {
            $line->quantity = $units;
        }

        return $line;
    }

    private function isValid(VariantData $variant, CartItem $line, Quantity $units): bool
    {
        $probe = $this->withUnits($line->replicate(), $units, $variant->saleUnit === SaleUnit::SquareMeter);

        return $this->resolveStock($variant, $probe) !== null;
    }

    private function resolveStock(VariantData $variant, CartItem $line): ?Quantity
    {
        try {
            return $this->quantities->resolve($variant, CartCalculator::inputOf($line))->stock;
        } catch (InvalidSaleQuantity) {
            return null;
        }
    }

    /** Largest multiple of the step ≤ max (and ≥ min). Units = pieces for SQUARE_METER. */
    private function clampToRules(VariantData $variant, Quantity $wanted): ?Quantity
    {
        $max = $variant->maxQuantity;
        $value = $max !== null && $wanted->greaterThan($max) ? $max : $wanted;
        $value = $value->floorToMultipleOf($variant->quantityStep);

        return $value->lessThan($variant->minQuantity) ? null : $value;
    }

    /** Largest valid quantity whose stock fits in $room (null when none). */
    private function fitToStock(VariantData $variant, CartItem $line, Quantity $room, bool $isArea): ?Quantity
    {
        if (! $room->isPositive()) {
            return null;
        }
        if ($isArea) {
            $one = $this->resolveStock($variant, $this->withUnits($line->replicate(), Quantity::ofUnits(1), true));
            if ($one === null || ! $one->isPositive()) {
                return null;
            }
            $units = Quantity::ofUnits(intdiv($room->milli(), $one->milli()));
        } else {
            $units = $room;
        }

        return $this->clampToRules($variant, $units);
    }
}
