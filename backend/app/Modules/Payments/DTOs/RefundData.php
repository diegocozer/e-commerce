<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Models\PaymentRefund;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/** Refund request (payment_refunds). */
final readonly class RefundData
{
    public function __construct(
        public int $id,
        public int $paymentId,
        public int $orderId,
        public Money $amount,
        public PaymentRefundStatus $status,
        public string $reason,
        public string $idempotencyKey,
        public ?string $externalId,
        public ?string $failureReason,
        public CarbonImmutable $requestedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    public static function fromModel(PaymentRefund $refund, int $orderId): self
    {
        return new self(
            id: $refund->id,
            paymentId: $refund->payment_id,
            orderId: $orderId,
            amount: Money::ofCents($refund->amount_cents),
            status: $refund->status,
            reason: $refund->reason,
            idempotencyKey: $refund->idempotency_key,
            externalId: $refund->external_id,
            failureReason: $refund->failure_reason,
            requestedAt: CarbonImmutable::parse($refund->created_at),
            completedAt: $refund->completed_at,
        );
    }
}
