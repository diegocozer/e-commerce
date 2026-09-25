<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartSnapshot;
use App\Modules\Customers\DTOs\CartMergeReport;
use App\Modules\Pricing\DTOs\CouponContext;
use App\Modules\Shipping\DTOs\CartLineLogisticsInput;
use App\Shared\Domain\Money;

/**
 * Cart facade (ARCHITECTURE.md §2.4 Cart). Consumed by Checkout and by the
 * Customers login/registration flow (through Customers\Contracts\GuestCartMerger,
 * which Cart implements — IMPLEMENTATION_PLAN.md §5.4).
 *
 * Every snapshot is recalculated (ADR-007): quantities through SaleQuantityResolver,
 * prices through PriceResolver (tiers by the variant total — ADR-019), stock through
 * InventoryService. Nothing coming from the client is trusted (ADR-012).
 */
interface CartService
{
    /** Recalculates everything. */
    public function snapshot(int $cartId, ?int $customerId): CartSnapshot;

    /** Active (not converted) cart of the customer, or null. */
    public function activeCartIdForCustomer(int $customerId): ?int;

    /**
     * Lines already resolved for Shipping\Contracts\ShippingRequestFactory::fromLines()
     * (only priceable lines).
     *
     * @return list<CartLineLogisticsInput>
     */
    public function toShippingLines(CartSnapshot $cart): array;

    /**
     * Canonical item configurations for the shipping QuoteHasher (SHIPPING.md §4.9).
     *
     * @return list<array{variant_id: int, quantity_milli: int|null, width_mm: int|null, height_mm: int|null, pieces: int|null}>
     */
    public function itemConfigs(CartSnapshot $cart): array;

    public function toCouponContext(CartSnapshot $cart, ?Money $shipping): CouponContext;

    /** Marks the cart converted (checkout transaction). */
    public function markConverted(int $cartId, int $orderId): void;

    /** ARCHITECTURE.md signature; see mergeGuestCart() for the report. */
    public function mergeGuestInto(string $guestToken, int $customerId): void;

    /**
     * Merges a valid guest cart into the customer's active cart (DATABASE.md §3.5.1,
     * RN-CAR-020) and deletes the guest cart. Returns null when the token does not
     * point to a valid guest cart. Also exposed as Customers\Contracts\GuestCartMerger.
     */
    public function mergeGuestCart(string $guestToken, int $customerId): ?CartMergeReport;
}
