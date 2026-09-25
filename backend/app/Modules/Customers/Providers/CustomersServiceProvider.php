<?php

declare(strict_types=1);

namespace App\Modules\Customers\Providers;

use App\Modules\Customers\Contracts\CustomerDirectory;
use App\Modules\Customers\Contracts\CustomerStatsProvider;
use App\Modules\Customers\Contracts\GuestCartMerger;
use App\Modules\Customers\Contracts\TermsVersionResolver;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Policies\CustomerAddressPolicy;
use App\Modules\Customers\Services\EloquentCustomerDirectory;
use App\Modules\Customers\Services\NullCustomerStatsProvider;
use App\Modules\Customers\Services\NullGuestCartMerger;
use App\Modules\Customers\Services\SettingsTableTermsVersionResolver;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;

final class CustomersServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        CustomerDirectory::class => EloquentCustomerDirectory::class,
        TermsVersionResolver::class => SettingsTableTermsVersionResolver::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        CustomerAddress::class => CustomerAddressPolicy::class,
    ];

    public function register(): void
    {
        parent::register();

        // Inversion defaults (IMPLEMENTATION_PLAN §5.4): Orders/Cart bind the real ones.
        $this->app->singletonIf(CustomerStatsProvider::class, NullCustomerStatsProvider::class);
        $this->app->singletonIf(GuestCartMerger::class, NullGuestCartMerger::class);

        // Blocked/anonymized customers lose their session on the next request
        // (API.md §1.2: 401 unauthenticated). Login itself answers 403 account_disabled.
        $this->app['config']->set('auth.providers.customers.driver', 'active_customers');
    }

    public function boot(): void
    {
        parent::boot();

        Auth::provider('active_customers', static fn (Application $app, array $config): EloquentUserProvider => (new EloquentUserProvider($app['hash'], Customer::class))
            ->withQuery(static fn ($query) => $query->where('is_active', true)->whereNull('anonymized_at')));
    }
}
