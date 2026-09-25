<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use App\Shared\Domain\Money;

final readonly class CouponContext
{
    /** @param  list<CouponLine>  $lines */
    public function __construct(
        public ?int $customerId,
        /** Products subtotal after price resolution, before coupon, without shipping. */
        public Money $subtotal,
        public array $lines,
        public ?Money $shipping,
    ) {}
}
