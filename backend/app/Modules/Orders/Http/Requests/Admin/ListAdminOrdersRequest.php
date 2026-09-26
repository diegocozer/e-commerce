<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Admin;

use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;

/** GET /admin/orders filters (API.md §3.G.9). */
final class ListAdminOrdersRequest extends FormRequest
{
    public const array SORTS = ['-placed_at', 'placed_at', 'paid_at', '-paid_at', '-total_cents', 'total_cents'];

    private const array LISTS = ['status', 'payment_status', 'shipping_method_type'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'min:1', 'max:100'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:'.implode(',', OrderStatus::values())],
            'payment_status' => ['nullable', 'array'],
            'payment_status.*' => ['string', 'in:'.implode(',', OrderPaymentStatus::values())],
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', PaymentMethod::values())],
            'shipping_method_type' => ['nullable', 'array'],
            'shipping_method_type.*' => ['string', 'in:pickup,own_delivery,table_rate,carrier'],
            'shipping_method_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'paid_from' => ['nullable', 'date_format:Y-m-d'],
            'paid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:paid_from'],
            'cancellation_requested' => ['nullable', 'in:0,1,true,false'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (self::LISTS as $key) {
            if (is_string($this->query($key))) {
                $merge[$key] = array_values(array_filter(explode(',', (string) $this->query($key))));
            }
        }
        $this->merge($merge);
    }
}
