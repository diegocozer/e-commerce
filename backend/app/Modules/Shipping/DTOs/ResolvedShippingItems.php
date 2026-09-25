<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** Output of Contracts\ShippingItemsResolver (implemented by Cart). */
final readonly class ResolvedShippingItems
{
    /** @param  list<CartLineLogisticsInput>  $lines */
    public function __construct(
        public array $lines,
        public int $subtotalCents,   // Σ line totals resolved by the PriceResolver
    ) {}
}
