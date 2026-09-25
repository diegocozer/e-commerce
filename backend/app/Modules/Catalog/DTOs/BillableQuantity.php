<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

use App\Shared\Domain\Quantity;

final readonly class BillableQuantity
{
    public function __construct(
        /** Billed (area with per-piece minimum, ADR-019; others = quantity). */
        public Quantity $billable,
        /** Stock movement (real area, no minimum). */
        public Quantity $stock,
        /** m² per piece (SQUARE_METER). */
        public ?Quantity $pieceArea,
        public bool $minimumAreaApplied,
        /** Resolved dimensions (SQUARE_METER; width = fixed width when applicable). */
        public ?int $widthMm = null,
        public ?int $heightMm = null,
        public ?int $pieces = null,
        /** ceil(weight_grams × billable_milli / 1000); KG without weight = billable in grams. */
        public int $weightGrams = 0,
    ) {}
}
