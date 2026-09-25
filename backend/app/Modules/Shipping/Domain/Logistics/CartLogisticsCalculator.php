<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Logistics;

use App\Modules\Shipping\DTOs\CartLineLogisticsInput;
use App\Modules\Shipping\DTOs\CartLogistics;
use App\Modules\Shipping\DTOs\LogisticsItem;
use App\Modules\Shipping\DTOs\ShippingPackage;
use App\Shared\Domain\AreaCalculator;
use App\Shared\Domain\SaleUnit;

/**
 * Converts cart lines into packages and totals (SHIPPING.md §2.3/§2.4, ADR-030).
 * Integer arithmetic only; derived weights are rounded UP. Lines with missing
 * logistics data never get an invented weight: they flag `missingData`.
 */
final class CartLogisticsCalculator
{
    public function __construct(private readonly int $rollMarginMm = 100) {}

    /** @param  iterable<CartLineLogisticsInput>  $lines */
    public function calculate(iterable $lines): CartLogistics
    {
        $packages = [];
        $items = [];
        $missing = [];
        $pickupOnly = false;
        $totalWeight = 0;

        foreach ($lines as $line) {
            $pickupOnly = $pickupOnly || $line->pickupOnly;
            $linePackages = $this->packagesFor($line);

            if ($linePackages === null) {
                $missing[] = $line->variantId;
                $items[] = new LogisticsItem($line->variantId, $line->sku, $line->saleUnit, $line->billable->milli(), 0, $line->lineTotalCents, $line->pickupOnly);

                continue;
            }

            [$lineWeight, $boxes] = $linePackages;
            $totalWeight += $lineWeight;
            array_push($packages, ...$boxes);
            $items[] = new LogisticsItem($line->variantId, $line->sku, $line->saleUnit, $line->billable->milli(), $lineWeight, $line->lineTotalCents, $line->pickupOnly);
        }

        $volume = 0;
        $largest = 0;
        foreach ($packages as $package) {
            $volume += $package->volumeCm3();
            $largest = max($largest, $package->largestDimensionMm());
        }

        return new CartLogistics(
            packages: $packages,
            items: $items,
            totalWeightGrams: $totalWeight,
            totalVolumeCm3: $volume,
            volumesCount: count($packages),
            largestDimensionMm: $largest,
            hasPickupOnlyItems: $pickupOnly,
            missingData: $missing !== [],
            variantsMissingData: array_values(array_unique($missing)),
        );
    }

    /** @return array{0: int, 1: list<ShippingPackage>}|null null = missing data */
    private function packagesFor(CartLineLogisticsInput $line): ?array
    {
        $milli = $line->billable->milli();
        $package = $line->package;
        $diameter = $package === null ? 0 : max($package->widthMm, $package->heightMm);

        switch ($line->saleUnit) {
            case SaleUnit::Unit:
            case SaleUnit::Roll:
            case SaleUnit::Box:
                if ($line->weightGrams <= 0 || $package === null) {
                    return null;
                }
                $units = intdiv($milli + 999, 1000);
                $weight = $line->weightGrams * $units;
                $count = max(1, intdiv($units + max(1, $line->unitsPerPackage ?? 1) - 1, max(1, $line->unitsPerPackage ?? 1)));

                return [$weight, $this->split($weight, $count, $package->lengthMm, $package->widthMm, $package->heightMm, $line->variantId)];

            case SaleUnit::LinearMeter:
                if ($line->weightGrams <= 0 || $diameter <= 0 || $line->fixedWidthMm === null || $line->fixedWidthMm <= 0) {
                    return null;
                }
                $weight = intdiv($line->weightGrams * $milli + 999, 1000);

                return [$weight, $this->split($weight, 1, $line->fixedWidthMm + $this->rollMarginMm, $diameter, $diameter, $line->variantId)];

            case SaleUnit::SquareMeter:
                $width = $line->widthMm ?? $line->fixedWidthMm;
                if ($line->weightGrams <= 0 || $diameter <= 0 || $width === null || $line->heightMm === null || $width <= 0 || $line->heightMm <= 0) {
                    return null;
                }
                // ADR-030: physical (real) area, without the minimum billable area.
                $area = AreaCalculator::calculate($width, $line->heightMm, max(1, $line->pieces ?? 1))->stockQuantity->milli();
                $weight = intdiv($line->weightGrams * $area + 999, 1000);

                return [$weight, $this->split($weight, 1, min($width, $line->heightMm) + $this->rollMarginMm, $diameter, $diameter, $line->variantId)];

            case SaleUnit::Kg:
                if ($package === null) {
                    return null;
                }

                // 1 kg = 1000 g ⇒ thousandths of a kg are grams.
                return [$milli, $this->split($milli, 1, $package->lengthMm, $package->widthMm, $package->heightMm, $line->variantId)];
        }

        return null;
    }

    /** @return list<ShippingPackage> weight split evenly; remainder on the first volume */
    private function split(int $weight, int $count, int $l, int $w, int $h, int $variantId): array
    {
        $each = intdiv($weight, $count);
        $packages = [];
        for ($i = 0; $i < $count; $i++) {
            $packages[] = new ShippingPackage($l, $w, $h, $each + ($i === 0 ? $weight % $count : 0), $variantId);
        }

        return $packages;
    }
}
