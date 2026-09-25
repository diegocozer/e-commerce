<?php

declare(strict_types=1);

namespace App\Modules\Settings\Providers;

use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Services\CachedSettingsRepository;
use App\Shared\Providers\ModuleServiceProvider;

final class SettingsServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        SettingsRepository::class => CachedSettingsRepository::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];
}
