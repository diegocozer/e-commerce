<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PaymentTransactionType;
use App\Modules\Payments\Events\PaymentRefunded;
use App\Modules\Payments\Events\PaymentRefundFailed;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Services\DefaultPaymentService;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Asynchronous refund with retry (ARCHITECTURE.md §4.7, DATABASE.md §3.7.2a):
 * gateway.refund(idempotency key = payment_refunds.idempotency_key) outside
 * any transaction, then T2 locks orders → payments → payment_refunds.
 */
final class ProcessRefund implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(public readonly int $refundId)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(PaymentGatewayManager $gateways, DefaultPaymentService $payments): void
    {
        $refund = PaymentRefund::query()->find($this->refundId);
        if ($refund === null || in_array($refund->status, [PaymentRefundStatus::Succeeded, PaymentRefundStatus::Failed], true)) {
            return;
        }

        $payment = Payment::query()->findOrFail($refund->payment_id);
        if ($refund->status === PaymentRefundStatus::Pending) {
            $refund->forceFill(['status' => PaymentRefundStatus::Processing])->save();
        }

        $result = $gateways->gateway($payment->provider->value)
            ->refund((string) $payment->external_id, Money::ofCents($refund->amount_cents), $refund->idempotency_key);

        if ($result->status === PaymentRefundStatus::Processing) {
            $this->release(300);

            return;
        }
        if ($result->status === PaymentRefundStatus::Failed) {
            throw new RuntimeException('refund rejected: '.($result->failureReason ?? 'rejected'));
        }

        DB::transaction(function () use ($refund, $payment, $result, $payments): void {
            DB::table('orders')->where('id', $payment->order_id)->lockForUpdate()->first(['id']);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $lockedRefund = PaymentRefund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($lockedRefund->status === PaymentRefundStatus::Succeeded) {
                return;
            }

            $now = CarbonImmutable::now();
            $lockedRefund->forceFill([
                'status' => PaymentRefundStatus::Succeeded,
                'external_id' => $result->externalId !== '' ? $result->externalId : null,
                'completed_at' => $now,
            ])->save();

            $before = $lockedPayment->status;
            $refunded = min($lockedPayment->amount_cents, $lockedPayment->refunded_cents + $lockedRefund->amount_cents);
            $after = $refunded >= $lockedPayment->amount_cents ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
            $lockedPayment->forceFill(['refunded_cents' => $refunded, 'status' => $after, 'refunded_at' => $now])->save();
            $payments->recordTransaction($lockedPayment, PaymentTransactionType::Refund, $before, $after, $lockedRefund->amount_cents, $lockedRefund->external_id, $result->sanitized, $lockedRefund->admin_user_id);

            Log::channel('payments')->info('refund.succeeded', ['payment_id' => $lockedPayment->id, 'order_id' => $lockedPayment->order_id, 'refund_id' => $lockedRefund->id]);
            PaymentRefunded::dispatch($lockedPayment->id, $lockedPayment->order_id, $lockedRefund->id, $lockedRefund->amount_cents, $now->toIso8601String());
        });
    }

    public function failed(?Throwable $exception): void
    {
        $refund = PaymentRefund::query()->find($this->refundId);
        if ($refund === null || $refund->status === PaymentRefundStatus::Succeeded) {
            return;
        }

        $refund->forceFill([
            'status' => PaymentRefundStatus::Failed,
            'failure_reason' => mb_substr($exception instanceof RuntimeException ? $exception->getMessage() : 'gateway_error', 0, 255),
            'completed_at' => CarbonImmutable::now(),
        ])->save();
        $orderId = (int) Payment::query()->whereKey($refund->payment_id)->value('order_id');

        Log::channel('payments')->error('refund.failed', ['refund_id' => $refund->id, 'payment_id' => $refund->payment_id, 'order_id' => $orderId, 'exception' => $exception !== null ? $exception::class : null]);
        PaymentRefundFailed::dispatch($refund->payment_id, $orderId, $refund->id, 'gateway_error', $this->attempts());
    }
}
