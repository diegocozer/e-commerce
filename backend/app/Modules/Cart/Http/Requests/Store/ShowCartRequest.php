<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

final class ShowCartRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shipping_quote_id' => ['nullable', 'uuid'],
            'shipping_option_id' => ['nullable', 'required_with:shipping_quote_id', 'string', 'max:100'],
        ];
    }
}
