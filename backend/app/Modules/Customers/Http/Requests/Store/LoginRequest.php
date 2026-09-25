<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Store;

use App\Modules\Customers\Http\Requests\Concerns\ProhibitsDangerousFields;
use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/login */
final class LoginRequest extends FormRequest
{
    use ProhibitsDangerousFields;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->prohibitedRules(),
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->prohibitedMessages();
    }
}
