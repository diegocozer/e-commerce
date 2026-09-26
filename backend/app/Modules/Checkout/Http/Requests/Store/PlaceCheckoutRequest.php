<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Requests\Store;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/** POST /checkout (API.md §3.E): whitelist + prohibited fields + Idempotency-Key header. */
final class PlaceCheckoutRequest extends CheckoutPreviewRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'shipping_quote_id' => ['required', 'uuid'],
            'shipping_option_id' => ['required', 'string', 'max:100'],
            'expected_total_cents' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'accept_terms' => ['accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! Str::isUuid((string) $this->header('Idempotency-Key', ''))) {
                $validator->errors()->add('idempotency_key', 'O cabeçalho Idempotency-Key (UUID) é obrigatório.');
            }
        }];
    }

    public function idempotencyKey(): string
    {
        return strtolower((string) $this->header('Idempotency-Key'));
    }

    /** Plain text (ARCHITECTURE: notes are stored as plain text). */
    public function notes(): ?string
    {
        $notes = $this->validated('notes');
        if (! is_string($notes)) {
            return null;
        }
        $notes = trim(strip_tags($notes));

        return $notes === '' ? null : $notes;
    }
}
