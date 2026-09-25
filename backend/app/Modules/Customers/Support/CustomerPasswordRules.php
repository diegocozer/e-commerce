<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use Closure;
use Illuminate\Validation\Rules\Password;

/** Customer password policy (SECURITY.md §3.2): defaults (8–72, letters+numbers, uncompromised in prod) + not containing the e-mail. */
final class CustomerPasswordRules
{
    /** @return list<mixed> */
    public static function rules(?string $email): array
    {
        return [
            'required', 'string', 'confirmed', 'max:72', Password::defaults(),
            static function (string $attribute, mixed $value, Closure $fail) use ($email): void {
                if (! is_string($value) || $email === null || $email === '') {
                    return;
                }
                $local = explode('@', mb_strtolower($email))[0];
                $lower = mb_strtolower($value);
                if (str_contains($lower, mb_strtolower($email)) || (mb_strlen($local) >= 4 && str_contains($lower, $local))) {
                    $fail('A senha não pode conter o seu e-mail.');
                }
            },
        ];
    }
}
