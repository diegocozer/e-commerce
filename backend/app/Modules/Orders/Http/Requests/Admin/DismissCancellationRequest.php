<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class DismissCancellationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'min:3', 'max:500']];
    }
}
