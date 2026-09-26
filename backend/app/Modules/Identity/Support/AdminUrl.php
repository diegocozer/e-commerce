<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

final class AdminUrl
{
    public static function resetPassword(string $token, string $email): string
    {
        $base = rtrim((string) (config('app.admin_url') ?? rtrim((string) config('app.url'), '/').'/admin'), '/');

        return $base.'/redefinir-senha?'.http_build_query(['token' => $token, 'email' => $email]);
    }
}
