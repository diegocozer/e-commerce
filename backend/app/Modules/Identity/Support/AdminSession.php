<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\AdminUser;
use App\Shared\Http\Middleware\EnsureAdminSessionIsFresh;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin session lifecycle: fixation-safe login, ADR-023 timestamps and the
 * password fingerprint that makes other sessions fall after a password
 * change/reset (checked by EnsureAdminPasswordUnchanged).
 */
final class AdminSession
{
    public const string PASSWORD_KEY = 'identity.admin_password';

    public static function start(Request $request, AdminUser $admin): void
    {
        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();
        EnsureAdminSessionIsFresh::start($request);
        self::rememberPassword($request, $admin);
    }

    public static function end(Request $request): void
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public static function rememberPassword(Request $request, AdminUser $admin): void
    {
        $request->session()->put(self::PASSWORD_KEY, self::fingerprint($admin));
    }

    /** "{id}|hmac(password hash)" — never the hash itself. */
    public static function fingerprint(AdminUser $admin): string
    {
        return $admin->id.'|'.hash_hmac('sha256', (string) $admin->getAuthPassword(), (string) config('app.key'));
    }

    public static function absoluteExpiresAt(Request $request): int
    {
        $start = (int) $request->session()->get(EnsureAdminSessionIsFresh::AUTHENTICATED_AT, now()->getTimestamp());

        return $start + (int) config('auth.admin_session.absolute_timeout_minutes', 480) * 60;
    }
}
