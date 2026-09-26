<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Admin;

use App\Modules\Shipping\Enums\ShippingPriceType;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST/PATCH /admin/shipping/rules (API.md §3.G.11, DATABASE §3.8.4 CHECKs). */
final class SaveRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $req = $this->route('rule') === null ? 'required' : 'sometimes';
        $nullableInt = ['sometimes', 'nullable', 'integer', 'min:0'];

        return [
            'method_id' => [$req, 'integer', Rule::exists('shipping_methods', 'id')->whereNull('deleted_at')],
            'zone_id' => ['sometimes', 'nullable', 'integer', Rule::exists('shipping_zones', 'id')],
            'name' => [$req, 'string', 'max:150'],
            'priority' => ['sometimes', 'integer', 'between:-1000000,1000000'],
            'min_weight_grams' => $nullableInt,
            'max_weight_grams' => $nullableInt,
            'min_subtotal_cents' => $nullableInt,
            'max_subtotal_cents' => $nullableInt,
            'min_volume_cm3' => $nullableInt,
            'max_volume_cm3' => $nullableInt,
            'max_package_length_cm' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999', 'regex:/^\d+(\.\d)?$/'],
            'price_type' => [$req, Rule::enum(ShippingPriceType::class)],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'per_kg_cents' => ['sometimes', 'integer', 'min:0'],
            'percentage_bp' => ['sometimes', 'integer', 'between:0,10000'],
            'min_price_cents' => $nullableInt,
            'max_price_cents' => $nullableInt,
            'delivery_days_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'delivery_days_max' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'expected_updated_at' => ['sometimes', 'nullable', 'date'],
            'id' => ['prohibited'],
            'summary' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            /** @var ShippingRule|null $rule */
            $rule = $this->route('rule');
            $value = fn (string $key): mixed => $this->has($key) ? $this->input($key) : $rule?->getAttribute($key);

            $method = ShippingMethod::query()->find($value('method_id'));
            if ($method !== null && ! $method->type->usesRules()) {
                $validator->errors()->add('method_id', 'Regras só podem ser criadas para métodos de entrega própria ou tabela de frete.');
            }

            foreach ([['min_weight_grams', 'max_weight_grams'], ['min_subtotal_cents', 'max_subtotal_cents'], ['min_volume_cm3', 'max_volume_cm3'], ['min_price_cents', 'max_price_cents'], ['delivery_days_min', 'delivery_days_max']] as [$min, $max]) {
                if ($value($min) !== null && $value($max) !== null && (int) $value($max) < (int) $value($min)) {
                    $validator->errors()->add($max, 'O valor máximo deve ser maior ou igual ao mínimo.');
                }
            }
            if ($value('valid_from') !== null && $value('valid_until') !== null && strtotime((string) $value('valid_until')) <= strtotime((string) $value('valid_from'))) {
                $validator->errors()->add('valid_until', 'O fim da vigência deve ser posterior ao início.');
            }

            $rawType = $value('price_type');
            $type = $rawType instanceof ShippingPriceType ? $rawType : ShippingPriceType::tryFrom((string) $rawType);
            if (in_array($type, [ShippingPriceType::PerKg, ShippingPriceType::FixedPlusPerKg], true) && (int) $value('per_kg_cents') <= 0) {
                $validator->errors()->add('per_kg_cents', 'Informe o valor por kg.');
            }
            if ($type === ShippingPriceType::PercentageOfSubtotal && ((int) $value('percentage_bp') < 1)) {
                $validator->errors()->add('percentage_bp', 'Informe o percentual (1 a 10000 pontos-base).');
            }
            if ($type === ShippingPriceType::Free && (int) $value('price_cents') !== 0) {
                $validator->errors()->add('price_cents', 'Regras de frete grátis não têm valor.');
            }
        }];
    }
}
