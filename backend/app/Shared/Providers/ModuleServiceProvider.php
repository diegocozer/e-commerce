<?php

declare(strict_types=1);

namespace App\Shared\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base class of every module provider (ARCHITECTURE.md §3.2).
 *
 * A module only edits files inside `app/Modules/<Module>`:
 *  - `$bindings`  interface => implementation (singletons; services are stateless);
 *  - `$listen`    event => listeners (explicit, event discovery is disabled);
 *  - `$policies`  model => policy;
 *  - `$commands`  artisan commands of the module;
 *  - schedule()   scheduled tasks of the module;
 *  - routes/<group>.php files, loaded automatically with the group attributes
 *    below (see ROUTE_GROUPS);
 *  - config/<name>.php merged as config('<name>'); lang/ and resources/views/
 *    registered under the lower-case module namespace (e.g. __('orders::x')).
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Route files per group. Route names are prefixed with the group "as".
     *
     *  store.php        public storefront API          /api/v1/...         (also customer auth: register/login/forgot)
     *  customer.php     authenticated customer         /api/v1/me/...
     *  admin_guest.php  public admin endpoints         /api/v1/admin/...   (admin login/forgot/reset password)
     *  admin.php        authenticated admin panel      /api/v1/admin/...   (declare a permission on every route)
     *  webhooks.php     gateways/carriers callbacks    /api/v1/webhooks/... (no session, no CSRF)
     *  web.php          SEO (sitemap, robots, shell)   /...
     */
    public const array ROUTE_GROUPS = [
        'store.php' => ['prefix' => 'api/v1', 'middleware' => ['api', 'throttle:api'], 'as' => 'store.'],
        'customer.php' => ['prefix' => 'api/v1/me', 'middleware' => ['api', 'auth:customer', 'throttle:customer'], 'as' => 'customer.'],
        'admin_guest.php' => ['prefix' => 'api/v1/admin', 'middleware' => ['api'], 'as' => 'admin.'],
        'admin.php' => ['prefix' => 'api/v1/admin', 'middleware' => ['api', 'auth:admin', 'admin.fresh', 'throttle:admin'], 'as' => 'admin.'],
        'webhooks.php' => ['prefix' => 'api/v1/webhooks', 'middleware' => ['webhook'], 'as' => 'webhooks.'],
        'web.php' => ['prefix' => '', 'middleware' => ['seo'], 'as' => 'seo.'],
    ];

    /** @var array<class-string, class-string|\Closure> interface => implementation */
    protected array $bindings = [];

    /** @var array<class-string, list<class-string>> event => listeners */
    protected array $listen = [];

    /** @var array<class-string, class-string> model => policy */
    protected array $policies = [];

    /** @var list<class-string> */
    protected array $commands = [];

    public function register(): void
    {
        foreach ($this->bindings as $abstract => $concrete) {
            $this->app->singleton($abstract, $concrete);
        }

        foreach (glob($this->modulePath('config/*.php')) ?: [] as $file) {
            $this->mergeConfigFrom($file, basename($file, '.php'));
        }
    }

    public function boot(): void
    {
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }

        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        if ($this->app->runningInConsole() && $this->commands !== []) {
            $this->commands($this->commands);
        }

        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule));

        $namespace = strtolower($this->moduleName());
        if (is_dir($this->modulePath('lang'))) {
            $this->loadTranslationsFrom($this->modulePath('lang'), $namespace);
        }
        if (is_dir($this->modulePath('resources/views'))) {
            $this->loadViewsFrom($this->modulePath('resources/views'), $namespace);
        }

        $this->loadModuleRoutes();
    }

    /** Override to register the module's scheduled tasks. */
    protected function schedule(Schedule $schedule): void {}

    /** "Orders" for App\Modules\Orders\Providers\OrdersServiceProvider. */
    protected function moduleName(): string
    {
        return explode('\\', static::class)[2];
    }

    protected function modulePath(string $path = ''): string
    {
        return app_path('Modules/'.$this->moduleName().($path !== '' ? '/'.$path : ''));
    }

    protected function loadModuleRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        foreach (self::ROUTE_GROUPS as $file => $attributes) {
            $path = $this->modulePath('routes/'.$file);
            if (is_file($path)) {
                Route::group($attributes, $path);
            }
        }
    }
}
