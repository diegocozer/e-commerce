<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

final class CartShippingQuoteRequest extends FormRequest
{
    use ProhibitsClientPricing;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->prohibitedFields(),
            'postal_code' => ['required_without:address_uuid', 'prohibits:address_uuid', 'nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'address_uuid' => ['required_without:postal_code', 'nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.prohibited' => 'O campo :attribute não é permitido.',
            'postal_code.regex' => 'CEP inválido.',
            'postal_code.prohibits' => 'Informe o CEP ou o endereço, não ambos.',
        ];
    }
}
