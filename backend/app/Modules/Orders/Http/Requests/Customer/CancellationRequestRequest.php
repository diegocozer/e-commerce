<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

final class CancellationRequestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'status' => ['prohibited'],
        ];
    }
}
