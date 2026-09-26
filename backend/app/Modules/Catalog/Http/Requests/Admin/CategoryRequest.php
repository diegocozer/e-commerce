<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Admin;

use App\Modules\Catalog\Support\SlugRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('id') !== null ? (int) $this->route('id') : null;
        $creating = $id === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'slug' => ['sometimes', 'nullable', ...SlugRules::rules('categories', 140, $id)],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'description_html' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'expected_updated_at' => ['sometimes', 'date'],
            'products_count' => ['prohibited'],
            'depth' => ['prohibited'],
        ];
    }
}
