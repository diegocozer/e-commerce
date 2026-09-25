<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

final readonly class ProductSearchResult
{
    /**
     * @param  list<int>  $productIds  ordered by relevance
     * @param  array<int, string>  $matchedSkus  product id => SKU matched by prefix
     * @param  array{variant_id: int, sku: string, product_id: int}|null  $exactSkuMatch
     */
    public function __construct(
        public array $productIds,
        public int $total,
        public array $matchedSkus = [],
        public ?array $exactSkuMatch = null,
    ) {}
}
