<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use App\Modules\Shipping\Enums\WeightBasis;

/** Logistics totals of a cart (SHIPPING.md §2.4). */
final readonly class CartLogistics
{
    /**
     * @param  list<ShippingPackage>  $packages
     * @param  list<LogisticsItem>  $items
     * @param  list<int>  $variantsMissingData
     */
    public function __construct(
        public array $packages,
        public array $items,
        public int $totalWeightGrams,
        public int $totalVolumeCm3,
        public int $volumesCount,
        public int $largestDimensionMm,
        public bool $hasPickupOnlyItems,
        public bool $missingData,
        public array $variantsMissingData = [],
    ) {}

    /** Synthetic logistics (admin simulator `logistics_override`). */
    public static function fromTotals(int $weightGrams, int $volumeCm3, int $largestDimensionMm): self
    {
        return new self([], [], $weightGrams, $volumeCm3, 1, $largestDimensionMm, false, false, []);
    }

    public function cubicWeightGrams(int $divisor): int
    {
        return intdiv($this->totalVolumeCm3 * 1000 + $divisor - 1, $divisor);
    }

    public function chargeableWeightGrams(int $divisor): int
    {
        return max($this->totalWeightGrams, $this->cubicWeightGrams($divisor));
    }

    public function effectiveWeightGrams(WeightBasis $basis, int $divisor): int
    {
        return $basis === WeightBasis::Chargeable ? $this->chargeableWeightGrams($divisor) : $this->totalWeightGrams;
    }

    /** @return array{total_weight_grams: int, total_volume_cm3: int, volumes_count: int, largest_dimension_mm: int} */
    public function summary(): array
    {
        return [
            'total_weight_grams' => $this->totalWeightGrams,
            'total_volume_cm3' => $this->totalVolumeCm3,
            'volumes_count' => $this->volumesCount,
            'largest_dimension_mm' => $this->largestDimensionMm,
        ];
    }
}
