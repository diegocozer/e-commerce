<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

final class RetryPaymentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'in:pix'],
            'idempotency_key' => ['nullable', 'uuid'],
            'amount_cents' => ['prohibited'],
            'total_cents' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->headers->has('Idempotency-Key')) {
            $this->merge(['idempotency_key' => (string) $this->header('Idempotency-Key')]);
        }
    }
}
