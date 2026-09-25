<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/** Everything Pricing needs about a variant (built by Catalog; Pricing never reads Catalog). */
final readonly class PricingSubject
{
    /** @param  list<int>  $categoryIdsWithAncestors */
    public function __construct(
        public int $variantId,
        public int $productId,
        public ?int $brandId,
        public array $categoryIdsWithAncestors,
        public Money $basePrice,
        public ?Money $promoPrice,
        public ?CarbonImmutable $promoStartsAt,
        public ?CarbonImmutable $promoEndsAt,
    ) {}
}
