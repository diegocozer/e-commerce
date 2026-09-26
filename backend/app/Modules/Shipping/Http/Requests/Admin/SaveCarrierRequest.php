<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Admin;

use App\Modules\Shipping\Carriers\CarrierRegistry;
use App\Modules\Shipping\Models\ShippingCarrier;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST/PATCH /admin/shipping/carriers (API.md §3.G.11). `credentials` is write-only. */
final class SaveCarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission:shipping.manage on the route
    }

    public function rules(): array
    {
        /** @var ShippingCarrier|null $carrier */
        $carrier = $this->route('carrier');
        $creating = $carrier === null;
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:100'],
            'code' => $creating
                ? ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('shipping_carriers', 'code')]
                : ['sometimes', 'string', static function (string $attr, mixed $value, Closure $fail) use ($carrier): void {
                    if ($value !== $carrier->code) {
                        $fail('O código da transportadora não pode ser alterado.');
                    }
                }],
            'driver' => [$req, 'string', 'max:40', static function (string $attr, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! app(CarrierRegistry::class)->has($value)) {
                    $fail('Driver de transportadora não registrado.');
                }
            }],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'array'],
            'settings.timeout_ms' => ['sometimes', 'nullable', 'integer', 'between:500,15000'],
            'settings.cubic_divisor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'settings.origin_postal_code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'expected_updated_at' => ['sometimes', 'nullable', 'date'],
            'id' => ['prohibited'],
            'has_credentials' => ['prohibited'],
            'methods_count' => ['prohibited'],
        ];
    }
}
