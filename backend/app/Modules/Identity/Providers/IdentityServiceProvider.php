<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Actions\AdminPasswordResets;
use App\Modules\Identity\Contracts\AdminUserDirectory;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Events\AdminRolesChanged;
use App\Modules\Identity\Listeners\EnsureAdminPasswordUnchanged;
use App\Modules\Identity\Listeners\ForgetPermissionCache;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Services\EloquentAdminUserDirectory;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        AdminUserDirectory::class => EloquentAdminUserDirectory::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [
        AdminRolesChanged::class => [ForgetPermissionCache::class],
        Authenticated::class => [EnsureAdminPasswordUnchanged::class],
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [];

    public function register(): void
    {
        parent::register();

        $config = $this->app['config'];
        // Deactivated/deleted admins lose their session on the next request (401); login answers 403.
        $config->set('auth.providers.admin_users.driver', 'active_admin_users');
        // Invitations (72 h) share the admin reset table (API.md §3.G.12).
        $config->set('auth.passwords.'.AdminPasswordResets::INVITES, [
            'provider' => 'admin_users',
            'table' => $config->get('auth.passwords.admin_users.table', 'admin_password_reset_tokens'),
            'expire' => 72 * 60,
            'throttle' => 0,
        ]);
    }

    public function boot(): void
    {
        parent::boot();

        Auth::provider('active_admin_users', static fn (Application $app, array $config): EloquentUserProvider => (new EloquentUserProvider($app['hash'], AdminUser::class))
            ->withQuery(static fn ($query) => $query->where('is_active', true)));

        // ADR-023: super-admin passes every ability/permission check.
        Gate::before(static fn (mixed $user): ?bool => $user instanceof AdminUser && $user->hasRole(AdminRole::SuperAdmin->value)
            ? true
            : null);
    }
}
