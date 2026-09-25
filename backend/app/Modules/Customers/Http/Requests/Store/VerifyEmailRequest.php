<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/email/verify */
final class VerifyEmailRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'hash' => ['required', 'string', 'size:40'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string', 'size:64'],
        ];
    }
}
