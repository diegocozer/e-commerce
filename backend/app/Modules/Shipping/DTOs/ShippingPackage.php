<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** One physical volume (SHIPPING.md §2.4). Millimetres and grams. */
final readonly class ShippingPackage
{
    public function __construct(
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
        public int $weightGrams,
        public int $variantId,
    ) {}

    /** ceil(l×w×h / 1000): mm³ → cm³ rounded up. */
    public function volumeCm3(): int
    {
        return intdiv($this->lengthMm * $this->widthMm * $this->heightMm + 999, 1000);
    }

    public function largestDimensionMm(): int
    {
        return max($this->lengthMm, $this->widthMm, $this->heightMm);
    }
}
