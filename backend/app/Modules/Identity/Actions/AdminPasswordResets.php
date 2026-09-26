<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Notifications\AdminResetPasswordNotification;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin reset links (30 min, broker `admin_users`) and invitations (72 h,
 * broker `admin_invites` registered by IdentityServiceProvider on the same
 * table, accepted only while the user never logged in).
 */
final class AdminPasswordResets
{
    public const string INVITES = 'admin_invites';

    /** Neutral: unknown/inactive e-mails silently do nothing. */
    public function sendLink(string $email): void
    {
        Password::broker('admin_users')->sendResetLink(
            ['email' => mb_strtolower(trim($email))],
            static function (AdminUser $admin, string $token): void {
                $admin->notify(new AdminResetPasswordNotification($token));
            },
        );
    }

    /** Link sent by another admin (password-reset / invitation); bypasses the broker throttle. */
    public function sendFor(AdminUser $admin): void
    {
        $invitation = $admin->last_login_at === null;
        /** @var PasswordBroker $broker */
        $broker = Password::broker($invitation ? self::INVITES : 'admin_users');
        $token = $broker->getRepository()->create($admin);
        $admin->notify(new AdminResetPasswordNotification($token, $invitation));
    }

    public function reset(string $email, string $token, string $password): AdminUser
    {
        $email = mb_strtolower(trim($email));
        $reset = null;
        $callback = static function (AdminUser $admin, string $password) use (&$reset): void {
            $admin->password = $password;
            $admin->setRememberToken(Str::random(60));
            $admin->save();
            $reset = $admin;
        };
        $credentials = ['email' => $email, 'token' => $token, 'password' => $password];

        $status = Password::broker('admin_users')->reset($credentials, $callback);
        if ($status === Password::INVALID_TOKEN) {
            $admin = AdminUser::query()->where('email', $email)->first();
            if ($admin !== null && $admin->last_login_at === null) {
                $status = Password::broker(self::INVITES)->reset($credentials, $callback);
            }
        }

        if ($status !== Password::PASSWORD_RESET || ! $reset instanceof AdminUser) {
            throw ValidationException::withMessages(['token' => 'Este link expirou ou é inválido.']);
        }

        return $reset;
    }
}
