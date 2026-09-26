<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Requests\Store;

use Illuminate\Validation\Validator;

/** API.md §1.7 (ADR-012): fields rejected with 422 `prohibited`, even when null. */
trait ProhibitsClientPricing
{
    /** @return list<string> */
    protected static function prohibitedNames(): array
    {
        return [
            'price', 'price_cents', 'unit_price_cents', 'base_unit_price_cents', 'line_total_cents',
            'subtotal_cents', 'total_cents', 'discount', 'discount_cents', 'shipping_price', 'shipping_cents',
            'shipping_price_cents', 'shipping_discount_cents', 'customer_id', 'company_id', 'status',
            'payment_status', 'price_list_id', 'is_active', 'email_verified_at', 'roles', 'permissions',
            'uuid', 'id',
        ];
    }

    /** @return array<string, list<string>> */
    protected function prohibitedFields(): array
    {
        return array_fill_keys(self::prohibitedNames(), ['prohibited']);
    }

    /** `prohibited` lets null/empty values through; API.md §1.7 rejects them too (even null). */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $input = $this->all();
            foreach (self::prohibitedNames() as $field) {
                if (array_key_exists($field, $input) && ! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, "O campo {$field} não é permitido.");
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['*.prohibited' => 'O campo :attribute não é permitido.'];
    }
}
