<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** POST /admin/orders/{id}/transitions (API.md §3.G.9). */
final class OrderTransitionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', 'in:processing,shipped,delivered,ready_for_pickup,picked_up'],
            'note' => ['nullable', 'string', 'max:1000'],
            'tracking_code' => ['nullable', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'string', 'url:https', 'max:500'],
            'carrier_name' => ['nullable', 'string', 'max:100'],
            'picked_up_by_name' => ['required_if:to_status,picked_up', 'nullable', 'string', 'min:3', 'max:150'],
            'picked_up_by_document' => ['required_if:to_status,picked_up', 'nullable', 'string', 'min:5', 'max:20', 'regex:/^[0-9A-Za-z.\-\/ ]+$/'],
            'status' => ['prohibited'],
            'payment_status' => ['prohibited'],
        ];
    }

    public function pickedUpDocument(): ?string
    {
        $doc = $this->validated('picked_up_by_document');

        return is_string($doc) ? strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $doc)) : null;
    }
}
