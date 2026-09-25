<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use App\Modules\Pricing\Enums\PriceSource;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;

final readonly class TierPrice
{
    public function __construct(
        public Quantity $minQuantity,
        public Money $unitPrice,
        public PriceSource $source,
    ) {}
}
