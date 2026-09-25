<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use App\Shared\Domain\SaleUnit;

/**
 * One cart/estimate line converted to logistics input (SHIPPING.md §2.3).
 * Built by the Cart module with the billable quantity already resolved.
 * Package dimensions are integer millimetres (use Dimension::cmToMm()).
 */
final readonly class CartLineLogisticsInput
{
    public function __construct(
        public int $variantId,
        public SaleUnit $saleUnit,
        public int $billableQuantityMilli,     // UNIT 3 → 3000; 5,5 m → 5500; 2,44 m² → 2440; 1,25 kg → 1250
        public ?int $widthMm,                  // SQUARE_METER: piece width
        public ?int $heightMm,                 // SQUARE_METER: piece height
        public ?int $pieces,                   // SQUARE_METER: number of pieces
        public ?int $fixedWidthMm,             // material width (required for LINEAR_METER)
        public ?int $weightGrams,              // product_variants.weight_grams (per sale unit); 0 = missing
        public ?int $packageLengthMm,
        public ?int $packageWidthMm,
        public ?int $packageHeightMm,
        public ?int $unitsPerPackage,          // null = 1
        public bool $pickupOnly,               // products.pickup_only
        public int $lineTotalCents,
        public string $sku,
        public ?int $quantityMilli = null,     // what the customer chose (cart_items.quantity), for the request hash
    ) {}

    /** @return array{variant_id: int, quantity_milli: int|null, width_mm: int|null, height_mm: int|null, pieces: int|null} */
    public function itemConfig(): array
    {
        return [
            'variant_id' => $this->variantId,
            'quantity_milli' => $this->saleUnit === SaleUnit::SquareMeter ? null : ($this->quantityMilli ?? $this->billableQuantityMilli),
            'width_mm' => $this->widthMm,
            'height_mm' => $this->heightMm,
            'pieces' => $this->pieces,
        ];
    }
}
