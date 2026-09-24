<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Exceptions\ErrorCode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin session limits (ADR-023): inactivity timeout (30 min) and absolute
 * lifetime (8 h). The Identity login action should call
 * EnsureAdminSessionIsFresh::start($request) right after a successful login;
 * sessions without the marker are initialized on first use.
 */
final class EnsureAdminSessionIsFresh
{
    public const string AUTHENTICATED_AT = 'admin_session.authenticated_at';

    public const string LAST_ACTIVITY_AT = 'admin_session.last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! Auth::guard('admin')->check()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();
        $authenticatedAt = (int) $session->get(self::AUTHENTICATED_AT, $now);
        $lastActivityAt = (int) $session->get(self::LAST_ACTIVITY_AT, $now);

        $idle = (int) config('auth.admin_session.idle_timeout_minutes', 30) * 60;
        $absolute = (int) config('auth.admin_session.absolute_timeout_minutes', 480) * 60;

        if ($now - $lastActivityAt > $idle || $now - $authenticatedAt > $absolute) {
            Auth::guard('admin')->logout();
            $session->forget([self::AUTHENTICATED_AT, self::LAST_ACTIVITY_AT]);
            $session->regenerateToken();

            return new JsonResponse([
                'message' => 'Sessão administrativa expirada. Entre novamente.',
                'code' => ErrorCode::AdminSessionExpired->value,
            ], 401);
        }

        $session->put(self::AUTHENTICATED_AT, $authenticatedAt);
        $session->put(self::LAST_ACTIVITY_AT, $now);

        return $next($request);
    }

    public static function start(Request $request): void
    {
        $now = now()->getTimestamp();
        $request->session()->put(self::AUTHENTICATED_AT, $now);
        $request->session()->put(self::LAST_ACTIVITY_AT, $now);
    }
}
