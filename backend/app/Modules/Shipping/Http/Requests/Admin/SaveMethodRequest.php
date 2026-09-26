<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Admin;

use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Enums\WeightBasis;
use App\Modules\Shipping\Models\ShippingMethod;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST/PATCH /admin/shipping/methods (API.md §3.G.11). `type` is immutable. */
final class SaveMethodRequest extends FormRequest
{
    private const array UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function authorize(): bool
    {
        return true;
    }

    private function current(): ?ShippingMethod
    {
        $method = $this->route('method');

        return $method instanceof ShippingMethod ? $method : null;
    }

    private function effectiveType(): ?ShippingMethodType
    {
        return $this->current()?->type ?? ShippingMethodType::tryFrom((string) $this->input('type'));
    }

    public function rules(): array
    {
        $method = $this->current();
        $creating = $method === null;
        $req = $creating ? 'required' : 'sometimes';
        $type = $this->effectiveType();
        $isCarrier = $type === ShippingMethodType::Carrier;
        $isPickup = $type === ShippingMethodType::Pickup;

        return [
            'name' => [$req, 'string', 'max:120'],
            'code' => [$req, 'string', 'max:60', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('shipping_methods', 'code')->whereNull('deleted_at')->ignore($method?->id)],
            'type' => $creating
                ? ['required', Rule::enum(ShippingMethodType::class)]
                : ['sometimes', static function (string $a, mixed $v, Closure $fail) use ($method): void {
                    if ($v !== $method->type->value) {
                        $fail('O tipo do método não pode ser alterado.');
                    }
                }],
            'carrier_id' => $isCarrier
                ? [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('shipping_carriers', 'id')]
                : ['sometimes', 'nullable', 'prohibited'],
            'carrier_service_code' => $isCarrier ? [$creating ? 'required' : 'sometimes', 'string', 'max:40'] : ['sometimes', 'nullable', 'prohibited'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'delivery_days_min' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'delivery_days_max' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'handling_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'weight_basis' => ['sometimes', Rule::enum(WeightBasis::class)],
            'cubic_divisor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'accepts_free_shipping_coupon' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'pickup' => $isPickup ? [$creating ? 'required' : 'sometimes', 'array'] : ['sometimes', 'nullable', 'prohibited'],
            'pickup.street' => [$isPickup ? 'required_with:pickup' : 'nullable', 'string', 'max:200'],
            'pickup.number' => ['nullable', 'string', 'max:20'],
            'pickup.complement' => ['nullable', 'string', 'max:100'],
            'pickup.district' => ['nullable', 'string', 'max:100'],
            'pickup.city' => [$isPickup ? 'required_with:pickup' : 'nullable', 'string', 'max:100'],
            'pickup.state' => [$isPickup ? 'required_with:pickup' : 'nullable', Rule::in(self::UFS)],
            'pickup.postal_code' => [$isPickup ? 'required_with:pickup' : 'nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'pickup.instructions' => ['nullable', 'string', 'max:500'],
            'pickup.opening_hours' => ['nullable', 'string', 'max:200'],
            'expected_updated_at' => ['sometimes', 'nullable', 'date'],
            'id' => ['prohibited'],
            'rules_count' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $method = $this->current();
            $min = $this->input('delivery_days_min', $method?->delivery_days_min ?? 0);
            $max = $this->input('delivery_days_max', $method?->delivery_days_max ?? $min);
            if (is_numeric($min) && is_numeric($max) && (int) $max < (int) $min) {
                $validator->errors()->add('delivery_days_max', 'O prazo máximo deve ser maior ou igual ao mínimo.');
            }
        }];
    }
}
