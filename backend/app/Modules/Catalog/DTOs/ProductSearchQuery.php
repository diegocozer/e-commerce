<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

final readonly class ProductSearchQuery
{
    /**
     * @param  list<int>|null  $productIds  restrict to these candidates (other filters applied by the caller); null = all active
     */
    public function __construct(
        public string $q,
        public ?array $productIds = null,
        public int $limit = 1000,
    ) {}
}
