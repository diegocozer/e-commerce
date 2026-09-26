<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use App\Modules\Checkout\DTOs\CheckoutData;
use App\Modules\Payments\Enums\PaymentMethod;
use PHPUnit\Framework\TestCase;

final class CheckoutFingerprintTest extends TestCase
{
    private function data(array $o = []): CheckoutData
    {
        return new CheckoutData(
            customerId: $o['customer'] ?? 1,
            idempotencyKey: $o['key'] ?? '3f6d2a8e-1b4c-4d5e-9f00-a1b2c3d4e5f6',
            addressUuid: $o['address'] ?? '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            shippingQuoteId: '5a1d7e2b-9c3f-4b8a-a6d2-0e4f1c2b3a90',
            shippingOptionId: $o['option'] ?? '2:2',
            paymentMethod: PaymentMethod::Pix,
            expectedTotalCents: $o['total'] ?? 9950,
            notes: array_key_exists('notes', $o) ? $o['notes'] : 'Entregar após 14h',
        );
    }

    public function test_fingerprint_covers_the_relevant_body_only(): void
    {
        $base = $this->data()->fingerprint();

        self::assertSame(64, strlen($base));
        self::assertSame($base, $this->data(['key' => 'other', 'customer' => 2])->fingerprint());
        self::assertSame($base, $this->data(['address' => '7C9E6679-7425-40DE-944B-E07FC1F90AE7'])->fingerprint());
        self::assertNotSame($base, $this->data(['option' => '1:pickup'])->fingerprint());
        self::assertNotSame($base, $this->data(['total' => 9951])->fingerprint());
        self::assertNotSame($base, $this->data(['notes' => null])->fingerprint());
    }
}
