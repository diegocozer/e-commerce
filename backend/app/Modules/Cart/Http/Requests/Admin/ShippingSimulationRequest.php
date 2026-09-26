<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** POST /admin/shipping/simulate (API.md §3.G.11): exactly one of items, order_id, logistics_override. */
final class ShippingSimulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission:shipping.manage on the route
    }

    public function rules(): array
    {
        return [
            'postal_code' => ['required', 'string', 'max:10'],
            'items' => ['sometimes', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'items.*.width_m' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100'],
            'items.*.height_m' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100'],
            'items.*.pieces' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'order_id' => ['sometimes', 'integer'],
            'logistics_override' => ['sometimes', 'array'],
            'logistics_override.total_weight_grams' => ['required_with:logistics_override', 'integer', 'min:0', 'max:10000000'],
            'logistics_override.total_volume_cm3' => ['required_with:logistics_override', 'integer', 'min:0', 'max:1000000000'],
            'logistics_override.largest_dimension_cm' => ['required_with:logistics_override', 'numeric', 'min:0', 'max:100000'],
            'subtotal_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'coupon_free_shipping' => ['sometimes', 'boolean'],
            'at' => ['sometimes', 'nullable', 'date'],
            'method_ids' => ['sometimes', 'array'],
            'method_ids.*' => ['integer'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $present = array_filter(['items', 'order_id', 'logistics_override'], fn (string $k): bool => $this->has($k));
            if (count($present) !== 1) {
                $validator->errors()->add('items', 'Informe exatamente um de: itens, pedido ou logística manual.');
            }
        }];
    }
}
