<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class CustomerIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'type' => ['sometimes', 'in:individual,company'],
            'is_active' => ['sometimes', 'in:0,1,true,false'],
            'price_list_id' => ['sometimes', 'integer', 'min:1'],
            'company_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'include_deleted' => ['sometimes', 'in:0,1,true,false'],
            'sort' => ['sometimes', 'in:name,-created_at,created_at,-total_spent,-last_order_at'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
