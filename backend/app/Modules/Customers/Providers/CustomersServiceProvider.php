<?php

declare(strict_types=1);

namespace App\Modules\Customers\Providers;

use App\Shared\Providers\ModuleServiceProvider;

final class CustomersServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string|\Closure> */
    protected array $bindings = [];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];
}
