<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\CancelReasonCode;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentService;
use App\Shared\Domain\ActorRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Expiry job body (ARCHITECTURE.md §4.5, RN-PAG-008): final gateway check
 * OUTSIDE any transaction, then per order a short transaction with
 * `FOR UPDATE SKIP LOCKED` that re-checks the status.
 */
final class ExpirePendingOrders
{
    public const int BATCH = 200;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly CancelOrder $cancel,
    ) {}

    /** @return int number of expired orders */
    public function execute(CarbonImmutable $now): int
    {
        $ids = Order::query()
            ->where('status', OrderStatus::PendingPayment->value)
            ->where('expires_at', '<', $now->subMinute())
            ->orderBy('expires_at')
            ->limit(self::BATCH)
            ->pluck('id');

        $expired = 0;
        foreach ($ids as $id) {
            $this->finalGatewayCheck((int) $id);

            $done = DB::transaction(function () use ($id): bool {
                $order = Order::query()->whereKey($id)
                    ->where('status', OrderStatus::PendingPayment->value)
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->first();
                if ($order === null) {
                    return false; // paid/cancelled meanwhile, or locked by the webhook
                }
                $this->cancel->cancelLocked($order, CancelReasonCode::PaymentExpired, ActorRef::system());

                return true;
            });
            $expired += $done ? 1 : 0;
        }

        return $expired;
    }

    private function finalGatewayCheck(int $orderId): void
    {
        $payment = $this->payments->latestForOrder($orderId);
        if ($payment === null || $payment->externalId === null || $payment->status->value !== 'pending') {
            return;
        }

        try {
            $this->payments->syncFromGateway($payment->provider->value, $payment->externalId);
        } catch (Throwable $e) {
            Log::channel('payments')->warning('order.expiry_sync_failed', ['order_id' => $orderId, 'exception' => $e::class]);
        }
    }
}
