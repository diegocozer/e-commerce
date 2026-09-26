<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Events\AdminLoggedIn;
use App\Modules\Identity\Events\AdminLoginFailed;
use App\Modules\Identity\Exceptions\AccountDisabled;
use App\Modules\Identity\Models\AdminUser;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use App\Shared\Support\Mask;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Credential check for the panel (neutral message, 403 account_disabled, audited). */
final class LoginAdmin
{
    public const string INVALID = 'E-mail ou senha inválidos.';

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(string $email, string $password, ?string $ip): AdminUser
    {
        $email = mb_strtolower(trim($email));
        $admin = AdminUser::query()->where('email', $email)->first();
        $emailHash = hash('sha256', $email);

        if ($admin === null || ! Hash::check($password, $admin->password)) {
            if ($admin === null) {
                Hash::check($password, '$2y$04$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            }
            $this->audit->record(new AuditEntry(
                ActorRef::system(),
                'admin_user.login_failed',
                $admin === null ? null : 'admin_user',
                $admin?->id,
                null,
                ['email' => Mask::email($email)],
            ));
            Log::channel('security')
                ->warning('admin.login_failed', ['email' => Mask::email($email), 'ip' => $ip]);
            event(new AdminLoginFailed($admin?->id, $emailHash, $ip));

            throw ValidationException::withMessages(['email' => self::INVALID]);
        }

        if (! $admin->is_active) {
            throw new AccountDisabled;
        }

        if (Hash::needsRehash($admin->password)) {
            $admin->password = $password;
        }
        $admin->last_login_at = now();
        $admin->last_login_ip = $ip;
        $admin->save();

        $this->audit->record(new AuditEntry(ActorRef::admin($admin->id), 'admin_user.login', 'admin_user', $admin->id));
        event(new AdminLoggedIn($admin->id, $emailHash, $ip));

        return $admin;
    }
}
