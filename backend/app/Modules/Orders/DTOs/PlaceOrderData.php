<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Pricing\DTOs\CouponContext;
use App\Modules\Pricing\DTOs\CouponEvaluation;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/**
 * Fully computed order (ARCHITECTURE.md §2.4 Orders). Checkout computes every
 * value; Orders only persists and enforces invariants.
 *
 * - shipping discount (free-shipping coupon) is derived:
 *   subtotal − discount + shippingTotal − total (must be within 0..shippingTotal);
 * - `$coupon` (valid evaluation) is redeemed by OrderPlacement with
 *   CouponService::redeem($coupon->code, $couponContext, $orderId) — so
 *   `$couponContext` is required whenever `$coupon` is given.
 */
final readonly class PlaceOrderData
{
    /** @param list<OrderLineData> $lines */
    public function __construct(
        public int $customerId,
        public string $idempotencyKey,
        public string $fingerprint,
        public CustomerSnapshot $customer,
        public AddressSnapshot $shippingAddress,
        public array $lines,
        public ShippingSnapshot $shipping,
        public ?CouponEvaluation $coupon,
        public Money $subtotal,
        public Money $discount,
        public Money $shippingTotal,
        public Money $total,
        public PaymentMethod $paymentMethod,
        public CarbonImmutable $expiresAt,
        public ?string $notes,
        public ?CouponContext $couponContext = null,
        public ?string $placedIp = null,
    ) {}

    public function shippingDiscount(): Money
    {
        return $this->subtotal->subtract($this->discount)->add($this->shippingTotal)->subtract($this->total);
    }
}
