<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/**
 * Inversion contract (ARCHITECTURE.md §2.3 rule 7): Inventory only knows
 * variant ids; Catalog implements this provider so the stock panel can show
 * SKU/names and filter by catalog attributes without Inventory depending on
 * Catalog.
 */
interface VariantLabelProvider
{
    /**
     * @param  list<int>  $variantIds
     * @return array<int, array{sku: string, name: string, product_id: int, product_name: string, product_slug: string, product_is_active: bool, sale_unit: string, min_quantity: string}>
     */
    public function labels(array $variantIds): array;

    /**
     * Variant ids matching catalog filters, ordered by SKU ascending.
     * Supported keys: q (SKU prefix / variant or product name), category_id
     * (includes descendants), brand_id, sale_unit (list), product_active (bool),
     * include_deleted (bool).
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    public function search(array $filters): array;
}
