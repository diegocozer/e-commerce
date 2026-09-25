<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Contracts;

use App\Modules\Pricing\DTOs\PriceContext;
use App\Modules\Pricing\DTOs\PriceQuote;

/**
 * Unit price resolution (ADR-005, RN-PRC). Candidates: base (+ quantity
 * tiers), effective price list (tiers or discount_bp fallback), variant promo
 * price, active promotions and customer/company prices. Lowest wins.
 */
interface PriceResolver
{
    public function resolve(PriceContext $ctx): PriceQuote;

    /**
     * Batch resolution (no N+1).
     *
     * @param  list<PriceContext>  $contexts
     * @return list<PriceQuote> same order as $contexts
     */
    public function resolveMany(array $contexts): array;
}
