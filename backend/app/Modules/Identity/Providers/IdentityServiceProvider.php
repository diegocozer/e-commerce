<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];

    public function boot(): void
    {
        parent::boot();

        // ADR-023: super-admin passes every ability/permission check.
        Gate::before(static fn (mixed $user): ?bool => $user instanceof AdminUser && $user->hasRole(AdminRole::SuperAdmin->value)
            ? true
            : null);
    }
}
