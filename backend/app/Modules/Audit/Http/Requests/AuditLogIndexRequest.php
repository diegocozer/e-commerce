<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AuditLogIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'actor_type' => ['sometimes', 'in:admin,customer,system'],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'string', 'max:100'],
            'auditable_type' => ['sometimes', 'string', 'max:40'],
            'auditable_id' => ['sometimes', 'integer', 'min:1'],
            'request_id' => ['sometimes', 'string', 'max:64'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort' => ['sometimes', 'in:-created_at,created_at'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
