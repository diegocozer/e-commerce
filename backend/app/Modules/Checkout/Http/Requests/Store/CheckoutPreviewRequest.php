<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** POST /checkout/preview (API.md §3.E). */
class CheckoutPreviewRequest extends FormRequest
{
    /** API.md §1.7 (+ `items`, `coupon_code`: items and coupon come from the cart). */
    public const array PROHIBITED = [
        'price', 'price_cents', 'unit_price_cents', 'base_unit_price_cents', 'line_total_cents',
        'subtotal_cents', 'total_cents', 'discount', 'discount_cents', 'shipping_price', 'shipping_cents',
        'shipping_price_cents', 'shipping_discount_cents', 'customer_id', 'company_id', 'status',
        'payment_status', 'price_list_id', 'is_active', 'email_verified_at', 'roles', 'permissions',
        'items', 'uuid', 'id', 'coupon_code', 'coupon', 'total', 'shipping', 'order_id',
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...array_fill_keys(self::PROHIBITED, ['prohibited']),
            'address_uuid' => ['required', 'uuid'],
            'shipping_quote_id' => ['nullable', 'uuid'],
            'shipping_option_id' => ['nullable', 'required_with:shipping_quote_id', 'string', 'max:100'],
            'payment_method' => ['required', 'in:pix'],
        ];
    }

    /** `prohibited` lets null/empty values through; API.md §1.7 rejects them too (even null). */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $input = $this->all();
            foreach (self::PROHIBITED as $field) {
                if (array_key_exists($field, $input) && ! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, "O campo {$field} não é permitido.");
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.prohibited' => 'O campo :attribute não é permitido.',
            'address_uuid.uuid' => 'Endereço inválido.',
        ];
    }
}
