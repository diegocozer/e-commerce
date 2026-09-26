<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Providers;

use App\Modules\Cart\Contracts\OrderShippingRequestSource;
use App\Modules\Checkout\Services\OrderShippingLines;
use App\Shared\Providers\ModuleServiceProvider;

final class CheckoutServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        OrderShippingRequestSource::class => OrderShippingLines::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];
}
