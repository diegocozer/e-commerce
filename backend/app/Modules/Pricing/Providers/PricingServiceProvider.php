<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Providers;

use App\Modules\Pricing\Contracts\CouponService;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Pricing\Contracts\PriceTierTable;
use App\Modules\Pricing\Services\DatabaseCouponService;
use App\Modules\Pricing\Services\DatabasePriceResolver;
use App\Shared\Providers\ModuleServiceProvider;

final class PricingServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        PriceResolver::class => DatabasePriceResolver::class,
        PriceTierTable::class => DatabasePriceResolver::class,
        CouponService::class => DatabaseCouponService::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];
}
