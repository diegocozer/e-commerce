<?php

declare(strict_types=1);

namespace App\Modules\Seo\Providers;

use App\Modules\Catalog\Events\CategoryTreeChanged;
use App\Modules\Catalog\Events\ProductDeleted;
use App\Modules\Catalog\Events\ProductSaved;
use App\Modules\Seo\Listeners\ForgetSeoCache;
use App\Shared\Providers\ModuleServiceProvider;

final class SeoServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [
        ProductSaved::class => [ForgetSeoCache::class],
        ProductDeleted::class => [ForgetSeoCache::class],
        CategoryTreeChanged::class => [ForgetSeoCache::class],
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [];
}
