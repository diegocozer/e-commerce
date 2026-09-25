<?php

declare(strict_types=1);

namespace App\Modules\Orders\Providers;

use App\Shared\Providers\ModuleServiceProvider;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];
}
