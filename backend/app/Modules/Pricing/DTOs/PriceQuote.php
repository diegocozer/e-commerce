<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use App\Modules\Pricing\Enums\PriceSource;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use Carbon\CarbonImmutable;

final readonly class PriceQuote
{
    public function __construct(
        public int $variantId,
        public Money $unitPrice,
        /** product_variants.price_cents */
        public Money $baseUnitPrice,
        /** round_half_up(unit × billable_milli / 1000) */
        public Money $lineTotal,
        public Quantity $billableQuantity,
        public PriceSource $source,
        /** Storefront label (API.md §2.1): null for base, "Preço Atacado", promotion name… */
        public ?string $sourceLabel,
        public ?int $promotionId,
        public ?int $priceListId,
        public ?string $promotionName = null,
        public ?CarbonImmutable $promotionEndsAt = null,
    ) {}

    /** "de R$ X por R$ Y": base when the resolved unit price is lower, else null. */
    public function compareAt(): ?Money
    {
        return $this->unitPrice->lessThan($this->baseUnitPrice) ? $this->baseUnitPrice : null;
    }
}
