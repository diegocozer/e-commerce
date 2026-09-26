<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Customer;

use App\Modules\Orders\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;

final class ListOrdersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:'.implode(',', OrderStatus::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('status'))) {
            $this->merge(['status' => array_values(array_filter(explode(',', (string) $this->query('status'))))]);
        }
    }
}
