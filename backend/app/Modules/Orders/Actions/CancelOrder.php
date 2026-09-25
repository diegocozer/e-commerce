<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Orders\Enums\CancelReasonCode;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Services\OrderStockReservations;
use App\Modules\Payments\Contracts\PaymentService;
use App\Modules\Pricing\Contracts\CouponService;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an order (ARCHITECTURE.md §4.5/§4.7, RN-PED-011/017):
 *  - pending_payment → release reservation, pending payment cancelled/expired, coupon released;
 *  - paid/processing → return to stock, full refund requested (async), coupon kept (RN-CUP-005).
 * Other statuses → 409 invalid_status_transition.
 */
final class CancelOrder
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
        private readonly PaymentService $payments,
        private readonly OrderStockReservations $reservations,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Order $order, CancelReasonCode $reasonCode, ActorRef $actor, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $reasonCode, $actor, $reason): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            return $this->cancelLocked($locked, $reasonCode, $actor, $reason);
        }, 2);
    }

    /** Must run inside a transaction with the order row locked. */
    public function cancelLocked(Order $order, CancelReasonCode $reasonCode, ActorRef $actor, ?string $reason = null): Order
    {
        $from = $order->status;
        $this->machine->assertTransition($from, OrderStatus::Cancelled, $actor, $order->shipping_method_type);
        $wasPaid = $from !== OrderStatus::PendingPayment;
        $reservation = $this->reservations->forOrder($order->id);

        if ($wasPaid) {
            $this->inventory->restock($reservation);
            $this->payments->requestRefund($order->id, null, $reason ?? 'Pedido cancelado', $actor);
        } else {
            if ($reasonCode === CancelReasonCode::PaymentExpired) {
                $this->payments->markExpired($order->id);
            } else {
                $this->payments->cancelPending($order->id);
            }
            $this->inventory->release($reservation);
            $this->coupons->releaseForOrder($order->id);
        }

        $order->forceFill([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
            'cancel_reason_code' => $reasonCode,
            'cancel_reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'expires_at' => null,
            'payment_status' => $reasonCode === CancelReasonCode::PaymentExpired ? OrderPaymentStatus::Expired : $order->payment_status,
        ])->save();

        $history = new OrderStatusHistory([
            'from_status' => $from,
            'to_status' => OrderStatus::Cancelled,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'note' => self::historyNote($reasonCode, $reason),
        ]);
        $history->order_id = $order->id;
        $history->save();

        if ($actor->type !== ActorType::System) {
            $this->audit->record(new AuditEntry($actor, 'order.cancelled', 'order', $order->id,
                ['status' => $from->value], ['status' => OrderStatus::Cancelled->value, 'cancel_reason_code' => $reasonCode->value, 'refund_requested' => $wasPaid]));
        }

        OrderCancelled::dispatch($order->id, $order->number, $reasonCode->value, $wasPaid, (string) $actor);

        return $order;
    }

    private static function historyNote(CancelReasonCode $code, ?string $reason): string
    {
        $label = match ($code) {
            CancelReasonCode::PaymentExpired => 'Pagamento não realizado no prazo',
            CancelReasonCode::Customer => 'Cancelado pelo cliente',
            CancelReasonCode::Admin => 'Cancelado pela loja',
            CancelReasonCode::PaymentFailed => 'Pagamento recusado',
        };

        return mb_substr($reason !== null && $reason !== '' ? "{$label}: {$reason}" : $label, 0, 1000);
    }
}
