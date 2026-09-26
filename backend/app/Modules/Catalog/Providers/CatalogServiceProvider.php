<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Contracts\CatalogQuery;
use App\Modules\Catalog\Contracts\ProductSearch;
use App\Modules\Catalog\Contracts\SaleQuantityResolver;
use App\Modules\Catalog\Contracts\StorefrontSeo;
use App\Modules\Catalog\Events\ProductSaved;
use App\Modules\Catalog\Listeners\ReindexProduct;
use App\Modules\Catalog\Services\CatalogStorefrontSeo;
use App\Modules\Catalog\Services\CatalogVariantLabelProvider;
use App\Modules\Catalog\Services\DefaultSaleQuantityResolver;
use App\Modules\Catalog\Services\EloquentCatalogQuery;
use App\Modules\Catalog\Services\PostgresProductSearch;
use App\Modules\Inventory\Contracts\VariantLabelProvider;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        CatalogQuery::class => EloquentCatalogQuery::class,
        SaleQuantityResolver::class => DefaultSaleQuantityResolver::class,
        ProductSearch::class => PostgresProductSearch::class,
        VariantLabelProvider::class => CatalogVariantLabelProvider::class,
        StorefrontSeo::class => CatalogStorefrontSeo::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [
        ProductSaved::class => [ReindexProduct::class],
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [];

    public function boot(): void
    {
        parent::boot();

        // GET /products uses `search` limits when q is present, else `catalog` (API.md §1.8).
        RateLimiter::for('catalog-products', static fn (Request $r) => $r->filled('q')
            ? Limit::perMinute(60)->by('search:'.$r->ip())
            : Limit::perMinute(120)->by('catalog:'.$r->ip()));
    }
}
