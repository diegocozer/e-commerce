<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\DTOs\ProductSearchQuery;
use App\Modules\Catalog\DTOs\ProductSearchResult;

/** ADR-014: full-text search behind an interface (Postgres now, Meilisearch later). */
interface ProductSearch
{
    /** Ordered product ids (by relevance) + total + exact SKU match. */
    public function search(ProductSearchQuery $q): ProductSearchResult;

    /** Postgres: recomputes products.search_vector. */
    public function index(int $productId): void;

    public function remove(int $productId): void;
}
