<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Contracts\CatalogQuery;
use App\Modules\Catalog\Contracts\ProductSearch;
use App\Modules\Catalog\Contracts\SaleQuantityResolver;
use App\Modules\Catalog\Events\ProductSaved;
use App\Modules\Catalog\Listeners\ReindexProduct;
use App\Modules\Catalog\Services\CatalogVariantLabelProvider;
use App\Modules\Catalog\Services\DefaultSaleQuantityResolver;
use App\Modules\Catalog\Services\EloquentCatalogQuery;
use App\Modules\Catalog\Services\PostgresProductSearch;
use App\Modules\Inventory\Contracts\VariantLabelProvider;
use App\Shared\Providers\ModuleServiceProvider;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        CatalogQuery::class => EloquentCatalogQuery::class,
        SaleQuantityResolver::class => DefaultSaleQuantityResolver::class,
        ProductSearch::class => PostgresProductSearch::class,
        VariantLabelProvider::class => CatalogVariantLabelProvider::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [
        ProductSaved::class => [ReindexProduct::class],
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [];
}
