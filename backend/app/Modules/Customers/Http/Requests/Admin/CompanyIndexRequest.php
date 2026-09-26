<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class CompanyIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'price_list_id' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'in:legal_name,-created_at,created_at'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
