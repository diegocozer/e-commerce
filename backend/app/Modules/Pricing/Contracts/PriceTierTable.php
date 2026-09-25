<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\DTOs\PriceContext;
use App\Modules\Pricing\DTOs\TierPrice;

/**
 * "Preço por quantidade" table for display (API.md VariantPrice.tiers):
 * resolved unit price for the current customer at each tier breakpoint.
 */
interface PriceTierTable
{
    /**
     * @param  \App\Shared\Domain\Quantity  $minimum  first breakpoint (min billable quantity)
     * @return list<TierPrice> ordered by min quantity; [] when the price does not vary with quantity
     */
    public function tiersFor(PriceContext $ctx, \App\Shared\Domain\Quantity $minimum): array;
}
