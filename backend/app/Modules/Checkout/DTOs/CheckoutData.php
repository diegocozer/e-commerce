<?php

declare(strict_types=1);

namespace App\Modules\Checkout\DTOs;

use App\Modules\Payments\Enums\PaymentMethod;

/** ARCHITECTURE.md §2.4 Checkout. The coupon comes from the cart, never from the body. */
final readonly class CheckoutData
{
    public function __construct(
        public int $customerId,
        public ?string $idempotencyKey,
        public string $addressUuid,
        public ?string $shippingQuoteId,
        public ?string $shippingOptionId,
        public PaymentMethod $paymentMethod,
        public ?int $expectedTotalCents,
        public ?string $notes,
        public ?string $ip = null,
    ) {}

    /**
     * API.md §1.9: sha256 of the canonical JSON of the relevant body fields
     * (the cart content is not part of it — EC-011).
     */
    public function fingerprint(): string
    {
        $data = [
            'address_uuid' => strtolower($this->addressUuid),
            'expected_total_cents' => $this->expectedTotalCents,
            'notes' => $this->notes,
            'payment_method' => $this->paymentMethod->value,
            'shipping_option_id' => $this->shippingOptionId,
            'shipping_quote_id' => $this->shippingQuoteId !== null ? strtolower($this->shippingQuoteId) : null,
        ];
        ksort($data);

        return hash('sha256', (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
