<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

final class AddCartItemRequest extends FormRequest
{
    use ProhibitsClientPricing;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->prohibitedFields(),
            'variant_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', QuantityRules::quantity()],
            'width_m' => ['nullable', QuantityRules::meters()],
            'height_m' => ['nullable', QuantityRules::meters()],
            'pieces' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
