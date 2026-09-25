<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentOrderContextProvider;
use App\Modules\Payments\Contracts\PaymentService;
use App\Modules\Payments\DTOs\GatewayPayment;
use App\Modules\Payments\DTOs\PaymentData;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\DTOs\RefundData;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PaymentTransactionType;
use App\Modules\Payments\Enums\RefundRequesterType;
use App\Modules\Payments\Events\PaymentAmountMismatch;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\PaymentCreated;
use App\Modules\Payments\Events\PaymentExpired;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Exceptions\PaymentGatewayUnavailable;
use App\Modules\Payments\Exceptions\PaymentNotFound;
use App\Modules\Payments\Exceptions\RefundNotAllowed;
use App\Modules\Payments\Jobs\ProcessRefund;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\ActorType;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * PaymentService (ARCHITECTURE.md §2.4, §4.3, §4.4, §4.7). Gateway calls
 * (`initiate`, `syncFromGateway`'s getPayment) happen OUTSIDE database
 * transactions; state changes lock `orders` → `payments` (global lock order,
 * DATABASE.md §4.2) and are idempotent.
 */
final class DefaultPaymentService implements PaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentOrderContextProvider $orders,
    ) {}

    public function createPending(PaymentRequest $request): PaymentData
    {
        $active = Payment::query()->where('order_id', $request->orderId)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Approved->value])
            ->lockForUpdate()->first();
        if ($active !== null) {
            return PaymentData::fromModel($active);
        }

        $provider = PaymentProvider::from($this->gateways->getDefaultDriver());
        if (! $this->gateways->gateway($provider->value)->supports($request->method)) {
            throw new LogicException("Payment method {$request->method->value} is not supported by {$provider->value}.");
        }

        $payment = new Payment(['expires_at' => $request->expiresAt]);
        $payment->forceFill([
            'order_id' => $request->orderId,
            'provider' => $provider,
            'method' => $request->method,
            'status' => PaymentStatus::Pending,
            'amount_cents' => $request->amount->cents(),
            'refunded_cents' => 0,
        ])->save();
        $payment->refresh();

        $this->record($payment, PaymentTransactionType::Create, null, PaymentStatus::Pending, $payment->amount_cents);
        PaymentCreated::dispatch($payment->id, $payment->order_id, $payment->method->value, $payment->expires_at?->toIso8601String());

        return PaymentData::fromModel($payment);
    }

    public function initiate(int $paymentId): PaymentData
    {
        $payment = Payment::query()->findOrFail($paymentId);
        if ($payment->external_id !== null || $payment->status !== PaymentStatus::Pending) {
            return PaymentData::fromModel($payment);
        }

        $context = $this->orders->forOrder($payment->order_id);
        if ($context === null) {
            throw new LogicException("Order {$payment->order_id} of payment {$payment->id} not found.");
        }

        $request = new PaymentRequest(
            orderId: $payment->order_id,
            orderNumber: $context->orderNumber,
            method: $payment->method,
            amount: Money::ofCents($payment->amount_cents),
            payer: $context->payer,
            expiresAt: $payment->expires_at ?? CarbonImmutable::now()->addMinutes(30),
            externalReference: $payment->uuid,
        );

        try {
            $gatewayPayment = $this->gateways->gateway($payment->provider->value)->createPayment($request, $payment->idempotency_key);
        } catch (PaymentGatewayUnavailable $e) {
            $this->record($payment, PaymentTransactionType::Create, PaymentStatus::Pending, PaymentStatus::Pending, $payment->amount_cents, payload: ['result' => 'create_failed']);
            Log::channel('payments')->warning('payment.create_failed', ['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'provider' => $payment->provider->value]);
            throw $e;
        }

        return DB::transaction(function () use ($payment, $gatewayPayment): PaymentData {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->external_id !== null) {
                return PaymentData::fromModel($locked);
            }

            $locked->fill([
                'external_id' => $gatewayPayment->externalId,
                'pix_copy_paste' => $gatewayPayment->pixCopyPaste,
                'pix_qr_code_base64' => $gatewayPayment->pixQrCodeBase64,
            ])->save();
            $this->record($locked, PaymentTransactionType::Create, PaymentStatus::Pending, PaymentStatus::Pending, $locked->amount_cents, $gatewayPayment->externalId, payload: ['result' => 'created']);
            Log::channel('payments')->info('payment.initiated', ['payment_id' => $locked->id, 'order_id' => $locked->order_id, 'provider' => $locked->provider->value]);

            return PaymentData::fromModel($locked);
        });
    }

    public function find(int $paymentId): ?PaymentData
    {
        $payment = Payment::query()->find($paymentId);

        return $payment === null ? null : PaymentData::fromModel($payment);
    }

    public function latestForOrder(int $orderId): ?PaymentData
    {
        $payment = Payment::query()->where('order_id', $orderId)->orderByDesc('id')->first();

        return $payment === null ? null : PaymentData::fromModel($payment);
    }

    public function markExpired(int $orderId): void
    {
        $this->closePending($orderId, PaymentStatus::Expired, PaymentTransactionType::Expire);
    }

    public function cancelPending(int $orderId): void
    {
        $this->closePending($orderId, PaymentStatus::Cancelled, PaymentTransactionType::Cancel);
    }

    public function requestRefund(int $orderId, ?Money $amount, string $reason, ActorRef $actor): RefundData
    {
        return DB::transaction(function () use ($orderId, $amount, $reason, $actor): RefundData {
            $payment = Payment::query()->where('order_id', $orderId)
                ->whereIn('status', [PaymentStatus::Approved->value, PaymentStatus::PartiallyRefunded->value])
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($payment === null) {
                throw new RefundNotAllowed;
            }

            $active = PaymentRefund::query()->where('payment_id', $payment->id)
                ->whereIn('status', [PaymentRefundStatus::Pending->value, PaymentRefundStatus::Processing->value])->first();
            if ($active !== null) {
                return RefundData::fromModel($active, $orderId);
            }

            $remaining = $payment->amount_cents - $payment->refunded_cents;
            $cents = $amount?->cents() ?? $remaining;
            if ($cents <= 0 || $cents > $remaining) {
                throw new RefundNotAllowed;
            }

            $isAdmin = $actor->type === ActorType::Admin;
            $refund = new PaymentRefund([
                'payment_id' => $payment->id,
                'amount_cents' => $cents,
                'reason' => mb_substr($reason, 0, 500),
                'requested_by_type' => $isAdmin ? RefundRequesterType::Admin : RefundRequesterType::System,
                'admin_user_id' => $isAdmin ? $actor->id : null,
            ]);
            $refund->forceFill(['status' => PaymentRefundStatus::Pending])->save();
            $refund->refresh();

            Log::channel('payments')->info('refund.requested', ['payment_id' => $payment->id, 'order_id' => $orderId, 'refund_id' => $refund->id, 'amount_cents' => $cents, 'actor' => (string) $actor]);
            ProcessRefund::dispatch($refund->id)->afterCommit();

            return RefundData::fromModel($refund, $orderId);
        });
    }

    public function latestRefundForOrder(int $orderId): ?RefundData
    {
        $refund = PaymentRefund::query()
            ->whereIn('payment_id', Payment::query()->select('id')->where('order_id', $orderId))
            ->orderByDesc('id')->first();

        return $refund === null ? null : RefundData::fromModel($refund, $orderId);
    }

    public function syncFromGateway(string $gateway, string $externalId, ?int $webhookEventId = null): PaymentData
    {
        $payment = Payment::query()->where('provider', $gateway)->where('external_id', $externalId)->first();
        if ($payment === null) {
            throw PaymentNotFound::forExternal($gateway, $externalId);
        }

        // Source of truth, outside any transaction.
        $remote = $this->gateways->gateway($gateway)->getPayment($externalId);

        return DB::transaction(function () use ($payment, $remote, $webhookEventId): PaymentData {
            // Global lock order: orders → payments.
            DB::table('orders')->where('id', $payment->order_id)->lockForUpdate()->first(['id']);
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $this->apply($locked, $remote, $webhookEventId);

            return PaymentData::fromModel($locked->refresh());
        }, 2);
    }

    private function apply(Payment $payment, GatewayPayment $remote, ?int $webhookEventId): void
    {
        $context = ['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'provider' => $payment->provider->value, 'remote_status' => $remote->status->value];

        if ($remote->externalReference !== null && $remote->externalReference !== $payment->uuid) {
            Log::channel('payments')->warning('payment.reference_mismatch', $context);

            return;
        }

        match ($remote->status) {
            PaymentStatus::Approved => $this->applyApproval($payment, $remote, $webhookEventId, $context),
            PaymentStatus::Failed, PaymentStatus::Cancelled => $this->applyFailure($payment, $remote, $webhookEventId, $context),
            default => Log::channel('payments')->info('payment.sync_noop', $context),
        };
    }

    /** @param array<string, mixed> $context */
    private function applyApproval(Payment $payment, GatewayPayment $remote, ?int $webhookEventId, array $context): void
    {
        if (in_array($payment->status, [PaymentStatus::Approved, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
            Log::channel('payments')->info('payment.already_approved', $context);

            return; // ADR-009: approving an approved payment is a no-op.
        }

        if ($remote->amount->cents() !== $payment->amount_cents || strtoupper($remote->currency) !== 'BRL') {
            $alreadyFlagged = PaymentTransaction::query()->where('payment_id', $payment->id)
                ->whereRaw("payload->>'anomaly' = 'amount_mismatch'")->exists();
            if (! $alreadyFlagged) {
                $this->record($payment, PaymentTransactionType::Sync, $payment->status, $payment->status, $remote->amount->cents(), $remote->externalId, $webhookEventId, [
                    'anomaly' => 'amount_mismatch', 'expected_cents' => $payment->amount_cents,
                    'received_cents' => $remote->amount->cents(), 'currency' => $remote->currency,
                ]);
                Log::channel('payments')->warning('payment.amount_mismatch', [...$context, 'expected_cents' => $payment->amount_cents, 'received_cents' => $remote->amount->cents()]);
                PaymentAmountMismatch::dispatch($payment->id, $payment->order_id, $payment->amount_cents, $remote->amount->cents());
            }

            return;
        }

        $before = $payment->status;
        $paidAt = $remote->approvedAt ?? CarbonImmutable::now();
        $payment->forceFill(['status' => PaymentStatus::Approved, 'paid_at' => $paidAt, 'failed_at' => null, 'failure_reason' => null])->save();
        $this->record($payment, PaymentTransactionType::Approve, $before, PaymentStatus::Approved, $payment->amount_cents, $remote->externalId, $webhookEventId, $remote->sanitized);
        Log::channel('payments')->info('payment.approved', [...$context, 'previous_status' => $before->value]);

        // Synchronous listener in Orders (MarkOrderAsPaid) runs in this transaction.
        PaymentApproved::dispatch($payment->id, $payment->order_id, $payment->amount_cents, $paidAt->toIso8601String(), $payment->provider->value);
    }

    /** @param array<string, mixed> $context */
    private function applyFailure(Payment $payment, GatewayPayment $remote, ?int $webhookEventId, array $context): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            Log::channel('payments')->info('payment.sync_noop', $context);

            return;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'failed_at' => CarbonImmutable::now(),
            'failure_reason' => $remote->statusDetail !== null ? mb_substr($remote->statusDetail, 0, 255) : 'rejected',
        ])->save();
        $this->record($payment, PaymentTransactionType::Fail, PaymentStatus::Pending, PaymentStatus::Failed, $payment->amount_cents, $remote->externalId, $webhookEventId, $remote->sanitized);
        Log::channel('payments')->info('payment.failed', $context);

        PaymentFailed::dispatch($payment->id, $payment->order_id, $remote->statusDetail ?? $remote->status->value);
    }

    private function closePending(int $orderId, PaymentStatus $to, PaymentTransactionType $type): void
    {
        DB::transaction(function () use ($orderId, $to, $type): void {
            $payments = Payment::query()->where('order_id', $orderId)->where('status', PaymentStatus::Pending->value)->lockForUpdate()->get();
            foreach ($payments as $payment) {
                $payment->forceFill(['status' => $to])->save();
                $this->record($payment, $type, PaymentStatus::Pending, $to, $payment->amount_cents);
                if ($to === PaymentStatus::Expired) {
                    PaymentExpired::dispatch($payment->id, $orderId);
                }
            }
        });
    }

    /** @param array<string, mixed>|null $payload */
    private function record(
        Payment $payment,
        PaymentTransactionType $type,
        ?PaymentStatus $before,
        PaymentStatus $after,
        int $amountCents,
        ?string $externalId = null,
        ?int $webhookEventId = null,
        ?array $payload = null,
        ?int $adminUserId = null,
    ): void {
        PaymentTransaction::query()->create([
            'payment_id' => $payment->id,
            'type' => $type,
            'status_before' => $before,
            'status_after' => $after,
            'amount_cents' => $amountCents,
            'external_id' => $externalId,
            'webhook_event_id' => $webhookEventId,
            'admin_user_id' => $adminUserId,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }

    /** Used by ProcessRefund. @param array<string, mixed>|null $payload */
    public function recordTransaction(Payment $payment, PaymentTransactionType $type, ?PaymentStatus $before, PaymentStatus $after, int $amountCents, ?string $externalId = null, ?array $payload = null, ?int $adminUserId = null): void
    {
        $this->record($payment, $type, $before, $after, $amountCents, $externalId, null, $payload, $adminUserId);
    }
}
