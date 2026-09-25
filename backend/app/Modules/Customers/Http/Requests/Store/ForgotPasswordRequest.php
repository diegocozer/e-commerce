<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/forgot-password */
final class ForgotPasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255']];
    }
}
