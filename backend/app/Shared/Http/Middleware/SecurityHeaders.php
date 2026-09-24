<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on API responses (SECURITY.md §14). nginx adds the full
 * set for HTML; this covers JSON served directly by PHP (and `php artisan serve`).
 */
final class SecurityHeaders
{
    /** Private/authenticated areas are never cached. */
    private const array NO_STORE_PATHS = ['api/v1/me', 'api/v1/me/*', 'api/v1/admin', 'api/v1/admin/*', 'api/v1/auth/*', 'api/v1/checkout*'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        if ($request->is(...self::NO_STORE_PATHS)) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
