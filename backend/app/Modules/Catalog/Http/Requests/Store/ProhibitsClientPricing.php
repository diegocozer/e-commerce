<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Store;

/** API.md §1.7: dangerous fields are rejected with 422 on storefront endpoints. */
trait ProhibitsClientPricing
{
    /** @return array<string, list<string>> */
    protected function prohibitedFields(): array
    {
        $fields = [
            'price', 'price_cents', 'unit_price_cents', 'base_unit_price_cents', 'line_total_cents', 'subtotal_cents',
            'total_cents', 'discount', 'discount_cents', 'shipping_price', 'shipping_cents', 'shipping_price_cents',
            'shipping_discount_cents', 'customer_id', 'company_id', 'status', 'payment_status', 'price_list_id',
            'is_active', 'email_verified_at', 'roles', 'permissions', 'uuid', 'id',
        ];

        return array_fill_keys($fields, ['prohibited']);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['*.prohibited' => 'O campo :attribute não é permitido.'];
    }
}
