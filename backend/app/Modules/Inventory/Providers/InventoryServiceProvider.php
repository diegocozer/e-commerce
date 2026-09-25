<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Contracts\InventoryRecords;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Inventory\Contracts\MovementReferenceResolver;
use App\Modules\Inventory\Services\DatabaseInventoryService;
use App\Modules\Inventory\Services\NullMovementReferenceResolver;
use App\Shared\Providers\ModuleServiceProvider;

final class InventoryServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        DatabaseInventoryService::class => DatabaseInventoryService::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(InventoryService::class, fn ($app) => $app->make(DatabaseInventoryService::class));
        $this->app->singleton(InventoryRecords::class, fn ($app) => $app->make(DatabaseInventoryService::class));
        // Orders (registered later) rebinds this with the real order-number resolver.
        $this->app->singletonIf(MovementReferenceResolver::class, NullMovementReferenceResolver::class);
    }
}
