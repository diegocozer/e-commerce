<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** Result of AreaCalculator::calculate(). All areas in thousandths of m². */
final readonly class AreaCalculation
{
    public function __construct(
        public Quantity $pieceArea,
        public int $pieces,
        /** Real area (stock movement, no minimum): pieceArea × pieces. */
        public Quantity $stockQuantity,
        /** Billed area: max(pieceArea, minimum) × pieces. */
        public Quantity $billableQuantity,
        public bool $minimumApplied,
    ) {}
}
