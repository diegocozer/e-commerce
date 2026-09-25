<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use App\Shared\Domain\PackageDimensions;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;

/**
 * One cart/estimate line converted to logistics input (SHIPPING.md §2.3,
 * IMPLEMENTATION_PLAN.md §5.6). Built by Cart with the billable quantity
 * already resolved. SQUARE_METER weight uses the REAL area
 * (width × height × pieces, ADR-030), so pass the resolved piece dimensions.
 */
final readonly class CartLineLogisticsInput
{
    public function __construct(
        public int $variantId,
        public SaleUnit $saleUnit,
        public Quantity $billable,              // UNIT 3 → 3.000; 5,5 m → 5.500; 1,25 kg → 1.250
        public ?int $widthMm,                   // SQUARE_METER: piece width (resolved)
        public ?int $heightMm,                  // SQUARE_METER: piece height
        public ?int $pieces,                    // SQUARE_METER: number of pieces
        public int $weightGrams,                // product_variants.weight_grams per sale unit (0 = missing, except KG)
        public ?PackageDimensions $package,     // mm; LINEAR/SQUARE: width/height = roll diameter
        public ?int $unitsPerPackage,           // null = 1
        public ?int $fixedWidthMm,              // material width (required for LINEAR_METER)
        public bool $pickupOnly,                // products.pickup_only
        public string $sku = '',                // for carriers (optional)
        public int $lineTotalCents = 0,         // declared value for carriers (optional)
    ) {}
}
