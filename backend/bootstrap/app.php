<?php

use App\Shared\Exceptions\ApiExceptionRenderer;
use App\Shared\Http\Middleware\EnsureAdminSessionIsFresh;
use App\Shared\Http\Middleware\ForceJsonResponse;
use App\Shared\Http\Middleware\RequestId;
use App\Shared\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

/*
| Module routes are registered by each module's ServiceProvider
| (App\Shared\Providers\ModuleServiceProvider); routes/api.php only holds the
| health checks. Listeners are registered explicitly (no event discovery).
*/
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
    )
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum stateful SPA: session + CSRF for SANCTUM_STATEFUL_DOMAINS origins.
        $middleware->statefulApi();

        $middleware->prepend(RequestId::class);
        $middleware->appendToGroup('api', [ForceJsonResponse::class, SecurityHeaders::class]);

        // Webhooks: no session, no CSRF, authenticated by HMAC signature.
        $middleware->group('webhook', [
            ForceJsonResponse::class,
            SecurityHeaders::class,
            'throttle:webhooks',
            SubstituteBindings::class,
        ]);

        // SEO endpoints (sitemap, robots, shell): public HTML/XML, no session.
        $middleware->group('seo', [
            'throttle:seo',
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'admin.fresh' => EnsureAdminSessionIsFresh::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        $middleware->validateCsrfTokens(except: ['api/v1/webhooks/*']);
        // php-fpm is only reachable through nginx (docker network), which sets X-Forwarded-*.
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
