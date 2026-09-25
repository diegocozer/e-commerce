<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\PaymentData;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\DTOs\RefundData;
use App\Modules\Payments\Exceptions\PaymentGatewayUnavailable;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\Money;

/**
 * Payments facade used by Checkout and Orders (ARCHITECTURE.md §2.4 Payments).
 * Payments knows the order only by id; it never depends on Orders.
 */
interface PaymentService
{
    /** Inside the checkout transaction: creates the local `pending` payment, no HTTP. */
    public function createPending(PaymentRequest $request): PaymentData;

    /**
     * OUTSIDE any transaction: calls the gateway (X-Idempotency-Key =
     * payments.idempotency_key) and stores the PIX QR/copy-paste. Idempotent:
     * a payment that already has an external id is returned as is.
     *
     * @throws PaymentGatewayUnavailable (503 payment_gateway_unavailable)
     */
    public function initiate(int $paymentId): PaymentData;

    public function find(int $paymentId): ?PaymentData;

    public function latestForOrder(int $orderId): ?PaymentData;

    /** Pending payment of the order becomes `expired` (idempotent). */
    public function markExpired(int $orderId): void;

    /** Pending payment of the order becomes `cancelled` (idempotent; customer/admin cancellation). */
    public function cancelPending(int $orderId): void;

    /**
     * Full refund of the approved payment of the order (partial refunds are out of
     * the MVP; `$amount` null = remaining amount). Creates a `pending` payment_refund
     * and dispatches ProcessRefund after commit. Idempotent: returns the active
     * refund when one exists.
     */
    public function requestRefund(int $orderId, ?Money $amount, string $reason, ActorRef $actor): RefundData;

    public function latestRefundForOrder(int $orderId): ?RefundData;

    /**
     * Called by the webhook job, the reconciliation and the expiry job: asks the
     * gateway (source of truth) and applies the transition idempotently.
     *
     * @throws PaymentGatewayUnavailable
     */
    public function syncFromGateway(string $gateway, string $externalId): PaymentData;
}
