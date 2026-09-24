<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Shared\Providers\ModuleServiceProvider;

final class NotificationsServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string|\Closure> */
    protected array $bindings = [];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];
}
