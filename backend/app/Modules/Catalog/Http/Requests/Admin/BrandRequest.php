<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Admin;

use App\Modules\Catalog\Support\SlugRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BrandRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('id') !== null ? (int) $this->route('id') : null;

        return [
            'name' => [$id === null ? 'required' : 'sometimes', 'string', 'min:2', 'max:120', Rule::unique('brands', 'name')->whereNull('deleted_at')->ignore($id)],
            'slug' => ['sometimes', 'nullable', ...SlugRules::rules('brands', 140, $id)],
            'is_active' => ['sometimes', 'boolean'],
            'expected_updated_at' => ['sometimes', 'date'],
            'products_count' => ['prohibited'],
        ];
    }
}
