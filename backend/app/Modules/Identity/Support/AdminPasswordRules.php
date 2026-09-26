<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Validation\Rules\Password;

/** Admin password policy (SECURITY.md §3.2): min 12, mixed case, numbers, symbols, uncompromised (production). */
final class AdminPasswordRules
{
    /** @return list<mixed> */
    public static function rules(): array
    {
        $policy = Password::min(12)->max(72)->mixedCase()->numbers()->symbols();
        if (app()->isProduction()) {
            $policy = $policy->uncompromised();
        }

        return ['required', 'string', 'confirmed', 'max:72', $policy];
    }
}
