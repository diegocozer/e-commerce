<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\DTOs\CouponContext;
use App\Modules\Pricing\DTOs\CouponEvaluation;
use App\Modules\Pricing\Exceptions\CouponInvalid;

/** Order-level coupons (RN-CUP). */
interface CouponService
{
    /** No side effects. Used by GET/PUT /cart/coupon and /checkout/preview. */
    public function evaluate(string $code, CouponContext $ctx): CouponEvaluation;

    /**
     * MUST run inside the checkout transaction: SELECT coupon FOR UPDATE,
     * checks total and per-customer limits, writes coupon_redemptions and
     * increments times_used.
     *
     * @throws CouponInvalid (409 coupon_invalid)
     */
    public function redeem(string $code, CouponContext $ctx, int $orderId): CouponEvaluation;

    /** Unpaid order cancelled/expired: gives the usage back (idempotent). */
    public function releaseForOrder(int $orderId): void;
}
