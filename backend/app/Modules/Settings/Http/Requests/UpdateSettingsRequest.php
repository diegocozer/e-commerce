<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Settings\Support\SettingRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** PATCH /admin/settings — { values: {key: value}, expected_updated_at? } */
final class UpdateSettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'values' => ['required', 'array', 'min:1'],
            'expected_updated_at' => ['sometimes', 'nullable', 'date'],
        ];

        $values = $this->input('values');
        if (is_array($values)) {
            foreach (array_keys($values) as $raw) {
                $key = SettingKey::tryFrom((string) $raw);
                if ($key !== null) {
                    $rules = [...$rules, ...SettingRules::for($key)];
                }
            }
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $values = $this->input('values');
            if (! is_array($values)) {
                return;
            }
            foreach (array_keys($values) as $raw) {
                if (SettingKey::tryFrom((string) $raw) === null) {
                    $validator->errors()->add('values.'.$raw, 'Configuração desconhecida.');
                }
            }
        }];
    }

    /** @return array<string, mixed> key => raw value (only whitelisted keys) */
    public function settingValues(): array
    {
        $values = (array) $this->input('values', []);

        return array_filter($values, static fn ($k): bool => SettingKey::tryFrom((string) $k) !== null, ARRAY_FILTER_USE_KEY);
    }
}
