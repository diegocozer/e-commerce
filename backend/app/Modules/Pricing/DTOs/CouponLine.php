<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use App\Shared\Domain\Money;

final readonly class CouponLine
{
    /** @param  list<int>  $categoryIds */
    public function __construct(
        public int $variantId,
        public int $productId,
        public array $categoryIds,
        public Money $lineTotal,
    ) {}
}
