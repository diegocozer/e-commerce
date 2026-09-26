<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\AdminSession;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * When the admin guard restores its user, compares the password fingerprint
 * kept in the session: a password changed elsewhere (reset, PUT /admin/me/password)
 * logs this session out → 401 on the request (SECURITY.md §3.2, SEC-AUTH-13).
 */
final class EnsureAdminPasswordUnchanged
{
    public function __construct(private readonly Request $request) {}

    public function handle(Authenticated $event): void
    {
        if ($event->guard !== 'admin' || ! $event->user instanceof AdminUser || ! $this->request->hasSession()) {
            return;
        }

        $session = $this->request->session();
        $stored = $session->get(AdminSession::PASSWORD_KEY);
        $current = AdminSession::fingerprint($event->user);

        if (! is_string($stored) || ! str_starts_with($stored, $event->user->id.'|')) {
            $session->put(AdminSession::PASSWORD_KEY, $current);

            return;
        }

        if (! hash_equals($stored, $current)) {
            $session->forget(AdminSession::PASSWORD_KEY);
            Auth::guard('admin')->logout();
        }
    }
}
