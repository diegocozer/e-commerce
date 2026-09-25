<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use App\Shared\Domain\SaleUnit;

/** Line aggregate for carriers (declared value, SKU) — SHIPPING.md §2.4. */
final readonly class LogisticsItem
{
    public function __construct(
        public int $variantId,
        public string $sku,
        public SaleUnit $saleUnit,
        public int $billableQuantityMilli,
        public int $weightGrams,
        public int $declaredValueCents,
        public bool $pickupOnly,
    ) {}
}
