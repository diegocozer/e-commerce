<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/**
 * Result of the checkout validation (SHIPPING.md §7): the option to charge and
 * the quote it belongs to (may be a NEW quote when silently accepted).
 * Order snapshot mapping: SHIPPING.md §5.1.
 */
final readonly class ValidatedShippingSelection
{
    public function __construct(
        public string $quoteId,
        public ShippingOption $option,
        public int $totalWeightGrams,
    ) {}
}
