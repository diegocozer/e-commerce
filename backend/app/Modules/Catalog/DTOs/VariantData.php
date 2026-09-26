<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

use App\Shared\Domain\PackageDimensions;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;

/** Sellable variant with the effective sale-unit rules (product + variant override). */
final readonly class VariantData
{
    /**
     * @param  list<int>  $categoryIdsWithAncestors
     * @param  array<string, string>  $attributes
     * @param  array<string, mixed>|null  $image
     */
    public function __construct(
        public int $id,
        public int $productId,
        public string $productSlug,
        public string $productName,
        public string $sku,
        public string $name,
        public SaleUnit $saleUnit,
        public bool $isActive,
        public bool $productIsActive,
        public bool $pickupOnly,
        public ?int $brandId,
        public int $primaryCategoryId,
        public array $categoryIdsWithAncestors,
        public Quantity $minQuantity,
        public ?Quantity $maxQuantity,
        public Quantity $quantityStep,
        /** Effective: variant override ?? product. */
        public ?int $fixedWidthMm,
        public ?int $minWidthMm,
        public ?int $maxWidthMm,
        public ?int $minHeightMm,
        public ?int $maxHeightMm,
        /** Per piece (ADR-019). */
        public ?Quantity $minBillableArea,
        /** Per sale unit (0 = missing). */
        public int $weightGrams,
        public ?PackageDimensions $package,
        public ?int $unitsPerPackage,
        public ?int $unitsPerBox,
        /** "50.000" */
        public ?string $rollLengthM,
        public ?string $imageUrl,
        public string $primaryCategorySlug = '',
        /** Display attributes {"cor": "Branco"} (API.md CartItem.variant.attributes). */
        public array $attributes = [],
        /** Cover image in the API ProductImage shape (variant image first, else product cover); null when none. */
        public ?array $image = null,
        /** Effective low-stock threshold (inventory override ?? settings default), stock unit — RN-CAT-017. */
        public ?Quantity $lowStockThreshold = null,
    ) {}

    /**
     * Storefront availability status (RN-CAT-017 / A-09) for an available quantity.
     *
     * @return 'in_stock'|'low_stock'|'out_of_stock'
     */
    public function availabilityStatus(Quantity $available): string
    {
        $min = $this->saleUnit === SaleUnit::SquareMeter ? Quantity::fromMilli(1) : $this->minQuantity;
        if (! $available->isPositive() || $available->lessThan($min)) {
            return 'out_of_stock';
        }

        return $this->lowStockThreshold !== null && $available->lessThanOrEqual($this->lowStockThreshold) ? 'low_stock' : 'in_stock';
    }

    /** Sellable = variant and product active (not deleted). */
    public function isSellable(): bool
    {
        return $this->isActive && $this->productIsActive;
    }

    /** "/{primary-category}/{product}" */
    public function urlPath(): string
    {
        return '/'.$this->primaryCategorySlug.'/'.$this->productSlug;
    }
}
