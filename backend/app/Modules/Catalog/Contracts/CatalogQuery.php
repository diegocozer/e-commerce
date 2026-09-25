<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Pricing\DTOs\PricingSubject;

/** Read side of the catalog for other modules (IMPLEMENTATION_PLAN.md §5.1). */
interface CatalogQuery
{
    /** Active or not (the caller decides); null when missing or soft-deleted. */
    public function variant(int $variantId): ?VariantData;

    /**
     * @param  list<int>  $variantIds
     * @return array<int, VariantData> indexed by id (no N+1)
     */
    public function variants(array $variantIds): array;

    /** null when the variant does not belong to the product (404). */
    public function variantBySlug(string $productSlug, int $variantId): ?VariantData;

    /** @throws \Illuminate\Database\Eloquent\ModelNotFoundException */
    public function pricingSubject(int $variantId): PricingSubject;

    /**
     * @param  list<int>  $variantIds
     * @return array<int, PricingSubject>
     */
    public function pricingSubjects(array $variantIds): array;
}
