<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Store;

use App\Modules\Customers\Support\CustomerPasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/reset-password */
final class ResetPasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => CustomerPasswordRules::rules(is_string($this->input('email')) ? $this->input('email') : null),
        ];
    }
}
