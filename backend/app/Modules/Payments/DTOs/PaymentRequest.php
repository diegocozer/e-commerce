<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/**
 * Charge request (ARCHITECTURE.md §2.4 Payments). `$externalReference` is filled
 * by the PaymentService with `payments.uuid` before calling the gateway (it is
 * checked back on getPayment — SECURITY.md §12.5); callers leave it null.
 */
final readonly class PaymentRequest
{
    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public PaymentMethod $method,
        public Money $amount,
        public PayerData $payer,
        public CarbonImmutable $expiresAt,
        public ?string $externalReference = null,
    ) {}

    public function withExternalReference(string $reference): self
    {
        return new self($this->orderId, $this->orderNumber, $this->method, $this->amount, $this->payer, $this->expiresAt, $reference);
    }
}
