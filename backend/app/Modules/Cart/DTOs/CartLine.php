<?php

declare(strict_types=1);

namespace App\Modules\Cart\DTOs;

use App\Modules\Cart\Enums\CartLineStatus;
use App\Modules\Catalog\DTOs\BillableQuantity;
use App\Modules\Catalog\DTOs\SaleInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Pricing\DTOs\PriceQuote;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Weight;

/**
 * One recalculated cart line (ARCHITECTURE.md §2.4 CartLine). `billable`/`price`
 * are null when the line is unavailable or its quantity became invalid.
 */
final readonly class CartLine
{
    /**
     * @param  list<array<string, mixed>>  $warnings  API.md CartItemWarning[]
     * @param  list<int|float>  $suggestions
     */
    public function __construct(
        public int $cartItemId,
        public int $variantId,
        public ?VariantData $variant,
        public SaleInput $input,
        public ?BillableQuantity $billable,
        public ?PriceQuote $price,
        public Quantity $available,
        public bool $isAvailable,
        public CartLineStatus $status,
        public array $warnings,
        public ?int $lastSeenUnitPriceCents,
        public Weight $weight,
        public ?string $issueMessage = null,
        public array $suggestions = [],
    ) {}

    public function isPriceable(): bool
    {
        return $this->status->isPriceable() && $this->price !== null && $this->billable !== null;
    }
}
