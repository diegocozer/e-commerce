<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AdminUserIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'role' => ['sometimes', 'string', 'max:50'],
            'is_active' => ['sometimes', 'in:0,1,true,false'],
            'include_deleted' => ['sometimes', 'in:0,1,true,false'],
            'sort' => ['sometimes', 'in:name,-name,created_at,-created_at,-last_login_at'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
