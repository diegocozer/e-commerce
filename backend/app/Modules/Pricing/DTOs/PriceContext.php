<?php

declare(strict_types=1);

namespace App\Modules\Pricing\DTOs;

use App\Shared\Domain\Quantity;
use Carbon\CarbonImmutable;

final readonly class PriceContext
{
    public function __construct(
        public PricingSubject $subject,
        /** Billable quantity of the line (line total). */
        public Quantity $billableQuantity,
        /** Sum of the variant in the cart (ADR-019); null = billableQuantity. */
        public ?Quantity $tierQuantity,
        public ?int $customerId,
        public CarbonImmutable $at,
    ) {}

    public function effectiveTierQuantity(): Quantity
    {
        return $this->tierQuantity ?? $this->billableQuantity;
    }
}
