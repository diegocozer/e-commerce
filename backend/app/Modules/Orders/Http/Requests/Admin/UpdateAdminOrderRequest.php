<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH /admin/orders/{id}: notes and tracking only; derived fields are prohibited. */
final class UpdateAdminOrderRequest extends FormRequest
{
    private const array PROHIBITED = [
        'status', 'payment_status', 'subtotal_cents', 'discount_cents', 'shipping_cents', 'shipping_discount_cents',
        'total_cents', 'items', 'customer_id', 'shipping_street', 'shipping_postal_code', 'shipping_number', 'address',
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'tracking_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tracking_url' => ['sometimes', 'nullable', 'string', 'url:https', 'max:500'],
            ...array_fill_keys(self::PROHIBITED, ['prohibited']),
        ];
    }
}
