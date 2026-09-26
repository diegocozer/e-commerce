<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/** POST /shipping/quote — product-page estimate (API.md §3.A). */
final class ProductShippingEstimateRequest extends FormRequest
{
    use ProhibitsClientPricing;

    /** `items` is the whole point of this endpoint: not prohibited here. */
    protected static function prohibitedNames(): array
    {
        return [
            'price', 'price_cents', 'unit_price_cents', 'base_unit_price_cents', 'line_total_cents',
            'subtotal_cents', 'total_cents', 'discount', 'discount_cents', 'shipping_price', 'shipping_cents',
            'shipping_price_cents', 'shipping_discount_cents', 'customer_id', 'company_id', 'status',
            'payment_status', 'price_list_id', 'is_active', 'email_verified_at', 'roles', 'permissions',
            'uuid', 'id', 'coupon_code',
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->prohibitedFields(),
            'postal_code' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*' => ['array'],
            'items.*.variant_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['nullable', QuantityRules::quantity()],
            'items.*.width_m' => ['nullable', QuantityRules::meters()],
            'items.*.height_m' => ['nullable', QuantityRules::meters()],
            'items.*.pieces' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.unit_price_cents' => ['prohibited'],
            'items.*.price' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.prohibited' => 'O campo :attribute não é permitido.',
            'postal_code.regex' => 'CEP inválido.',
            'postal_code.required' => 'CEP inválido.',
        ];
    }
}
