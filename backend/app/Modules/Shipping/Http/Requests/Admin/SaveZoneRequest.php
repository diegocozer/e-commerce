<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Admin;

use App\Modules\Shipping\PostalCode\PostalCodeNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST/PATCH /admin/shipping/zones — nested collections replace the current ones (sync). */
final class SaveZoneRequest extends FormRequest
{
    private const array UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $req = $this->route('zone') === null ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'postal_ranges' => ['sometimes', 'array', 'max:500'],
            'postal_ranges.*.start_postal_code' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'postal_ranges.*.end_postal_code' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'cities' => ['sometimes', 'array', 'max:1000'],
            'cities.*.city_ibge_code' => ['required', 'string', 'distinct', Rule::exists('ibge_cities', 'ibge_code')],
            'states' => ['sometimes', 'array', 'max:27'],
            'states.*' => ['string', 'distinct', Rule::in(self::UFS)],
            'expected_updated_at' => ['sometimes', 'nullable', 'date'],
            'id' => ['prohibited'],
            'rules_count' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('postal_ranges', []) as $i => $range) {
                $start = PostalCodeNormalizer::tryNormalize((string) ($range['start_postal_code'] ?? ''));
                $end = PostalCodeNormalizer::tryNormalize((string) ($range['end_postal_code'] ?? ''));
                if ($start !== null && $end !== null && $start > $end) {
                    $validator->errors()->add("postal_ranges.{$i}.end_postal_code", 'O CEP final deve ser maior ou igual ao inicial.');
                }
            }
        }];
    }
}
