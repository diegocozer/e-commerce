<?php

declare(strict_types=1);

namespace App\Shared\Http\RateLimiting;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Named rate limiters of SECURITY.md §18 / ADR-006. Use them in routes with
 * `->middleware('throttle:<name>')`. 429 responses are rendered as
 * {message, code: too_many_requests} with Retry-After.
 */
final class RateLimiters
{
    public static function register(): void
    {
        RateLimiter::for('api', static fn (Request $r) => Limit::perMinute(120)->by(self::actorKey($r)));

        RateLimiter::for('login', static fn (Request $r) => [
            Limit::perMinute(5)->by('login:'.self::emailKey($r)),
            Limit::perMinute(20)->by('login-ip:'.$r->ip()),
        ]);
        RateLimiter::for('register', static fn (Request $r) => [
            Limit::perMinute(5)->by('register:'.$r->ip()),
            Limit::perHour(20)->by('register-h:'.$r->ip()),
        ]);
        $password = static fn (Request $r) => Limit::perMinute(5)->by('password:'.self::emailKey($r));
        RateLimiter::for('password', $password);
        RateLimiter::for('password-reset', $password);

        RateLimiter::for('catalog', static fn (Request $r) => Limit::perMinute(120)->by('catalog:'.$r->ip()));
        RateLimiter::for('search', static fn (Request $r) => Limit::perMinute(60)->by('search:'.$r->ip()));
        RateLimiter::for('price-quote', static fn (Request $r) => Limit::perMinute(60)->by('price-quote:'.self::actorKey($r)));
        RateLimiter::for('cart', static fn (Request $r) => Limit::perMinute(60)->by('cart:'.self::cartKey($r)));
        RateLimiter::for('coupon', static fn (Request $r) => [
            Limit::perMinute(10)->by('coupon:'.self::actorKey($r)),
            Limit::perHour(30)->by('coupon-h:'.self::actorKey($r)),
        ]);
        RateLimiter::for('postal-code', static fn (Request $r) => Limit::perMinute(20)->by('postal-code:'.$r->ip()));
        RateLimiter::for('shipping-quote', static fn (Request $r) => Limit::perMinute(10)->by('shipping-quote:'.self::actorKey($r)));
        RateLimiter::for('checkout', static fn (Request $r) => [
            Limit::perMinute(5)->by('checkout:'.self::actorKey($r)),
            Limit::perHour(30)->by('checkout-h:'.self::actorKey($r)),
        ]);
        RateLimiter::for('customer', static fn (Request $r) => Limit::perMinute(60)->by('customer:'.self::actorKey($r)));
        RateLimiter::for('admin', static fn (Request $r) => Limit::perMinute(300)->by('admin:'.self::adminKey($r)));
        RateLimiter::for('admin-heavy', static fn (Request $r) => Limit::perMinute(10)->by('admin-heavy:'.self::adminKey($r)));
        RateLimiter::for('uploads', static fn (Request $r) => Limit::perMinute(30)->by('uploads:'.self::adminKey($r)));
        RateLimiter::for('webhooks', static fn (Request $r) => Limit::perMinute(300)->by('webhooks:'.$r->ip()));
        RateLimiter::for('health', static fn (Request $r) => Limit::perMinute(60)->by('health:'.$r->ip()));
        RateLimiter::for('seo', static fn (Request $r) => Limit::perMinute(300)->by('seo:'.$r->ip()));
    }

    /** "customer:{id}" when logged in as a customer, otherwise the client IP. */
    public static function actorKey(Request $request): string
    {
        $customerId = Auth::guard('customer')->id();

        return $customerId !== null ? 'customer:'.$customerId : 'ip:'.$request->ip();
    }

    private static function adminKey(Request $request): string
    {
        $adminId = Auth::guard('admin')->id();

        return $adminId !== null ? 'admin:'.$adminId : 'ip:'.$request->ip();
    }

    private static function cartKey(Request $request): string
    {
        $token = (string) $request->header('X-Cart-Token', '');

        return Auth::guard('customer')->check() || $token === ''
            ? self::actorKey($request)
            : 'token:'.sha1($token);
    }

    private static function emailKey(Request $request): string
    {
        return sha1(mb_strtolower(trim((string) $request->input('email', '')))).'|'.$request->ip();
    }
}
