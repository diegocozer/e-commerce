<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class CouponRedeemed
{
    use Dispatchable;

    public function __construct(
        public readonly int $couponId,
        public readonly int $orderId,
        public readonly ?int $customerId,
        public readonly int $discountCents,
    ) {}
}
