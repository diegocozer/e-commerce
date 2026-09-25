<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use App\Modules\Pricing\Enums\CouponType;
use App\Shared\Domain\Money;

final readonly class CouponEvaluation
{
    public function __construct(
        public bool $valid,
        /**
         * null when valid; else not_found | inactive | expired | min_order_not_met |
         * usage_limit_reached | customer_limit_reached | login_required (API.md CartCoupon.reason_code).
         */
        public ?string $reasonCode,
        /** pt-BR message ready for 422/409. */
        public ?string $message,
        /** Discount on the products subtotal (0 for free_shipping or invalid); never above subtotal. */
        public Money $discount,
        public bool $freeShipping,
        public ?int $couponId,
        public ?string $code,
        public ?CouponType $type = null,
        public ?string $description = null,
    ) {}
}
