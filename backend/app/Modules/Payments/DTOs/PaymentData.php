<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/** Read model of one payment (charge attempt). */
final readonly class PaymentData
{
    public function __construct(
        public int $id,
        public string $uuid,
        public int $orderId,
        public PaymentProvider $provider,
        public PaymentMethod $method,
        public PaymentStatus $status,
        public Money $amount,
        public Money $refunded,
        public ?string $externalId,
        public ?string $pixCopyPaste,
        public ?string $pixQrCodeBase64,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $paidAt,
        public ?string $failureReason,
        public CarbonImmutable $createdAt,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        return new self(
            id: $payment->id,
            uuid: $payment->uuid,
            orderId: $payment->order_id,
            provider: $payment->provider,
            method: $payment->method,
            status: $payment->status,
            amount: Money::ofCents($payment->amount_cents),
            refunded: Money::ofCents($payment->refunded_cents ?? 0),
            externalId: $payment->external_id,
            pixCopyPaste: $payment->pix_copy_paste,
            pixQrCodeBase64: $payment->pix_qr_code_base64,
            expiresAt: $payment->expires_at,
            paidAt: $payment->paid_at,
            failureReason: $payment->failure_reason,
            createdAt: CarbonImmutable::parse($payment->created_at),
        );
    }

    /** The gateway charge was created (external id and PIX data stored). */
    public function isInitiated(): bool
    {
        return $this->externalId !== null;
    }

    /** Pending PIX that can still be paid. */
    public function hasUsablePix(?CarbonImmutable $now = null): bool
    {
        return $this->status === PaymentStatus::Pending
            && $this->pixCopyPaste !== null
            && ($this->expiresAt === null || $this->expiresAt->isAfter($now ?? CarbonImmutable::now()));
    }
}
