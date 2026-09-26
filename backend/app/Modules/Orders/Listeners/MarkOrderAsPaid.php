<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Orders\Enums\CancelReasonCode;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\LatePaymentRefundRequested;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Services\OrderStockReservations;
use App\Modules\Payments\Contracts\PaymentService;
use App\Modules\Payments\Events\PaymentApproved;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\ActorType;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * PaymentApproved → order paid (SYNCHRONOUS, inside the Payments transaction
 * that already locked orders → payments). ARCHITECTURE.md §4.4, ADR-022:
 *  - pending_payment → paid + stock commit;
 *  - cancelled by expiry (never paid) → reactivated (cancelled → paid, system) when
 *    every item has stock (reserve + commit), else automatic full refund;
 *  - cancelled by customer/admin, or already paid by another charge → refund (EC-006).
 */
final class MarkOrderAsPaid
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly InventoryService $inventory,
        private readonly PaymentService $payments,
        private readonly OrderStockReservations $reservations,
    ) {}

    public function handle(PaymentApproved $event): void
    {
        $order = Order::query()->lockForUpdate()->find($event->orderId);
        if ($order === null) {
            Log::channel('payments')->error('order.paid_unknown_order', ['order_id' => $event->orderId, 'payment_id' => $event->paymentId]);

            return;
        }

        $paidAt = CarbonImmutable::parse($event->approvedAt);

        if ($order->status === OrderStatus::PendingPayment) {
            $this->inventory->commit($this->reservations->forOrder($order->id));
            $this->markPaid($order, OrderStatus::PendingPayment, $paidAt, 'Pagamento PIX aprovado.', false);

            return;
        }

        if ($order->status === OrderStatus::Cancelled && $order->cancel_reason_code === CancelReasonCode::PaymentExpired && $order->paid_at === null) {
            if ($this->hasStockFor($order)) {
                $reservation = $this->reservations->forOrder($order->id);
                $this->inventory->reserve($reservation);
                $this->inventory->commit($reservation);
                $order->forceFill(['cancelled_at' => null, 'cancel_reason_code' => null, 'cancel_reason' => null]);
                $this->markPaid($order, OrderStatus::Cancelled, $paidAt, 'Reativado por pagamento tardio.', true);

                return;
            }

            $this->refund($order, $event, 'Pagamento tardio sem estoque disponível (estorno automático).');

            return;
        }

        $this->refund($order, $event, $order->status === OrderStatus::Cancelled
            ? 'Pagamento recebido para pedido cancelado (estorno automático).'
            : 'Pagamento em duplicidade (estorno automático).');
    }

    private function markPaid(Order $order, OrderStatus $from, CarbonImmutable $paidAt, string $note, bool $reactivated): void
    {
        $this->machine->assertTransition($from, OrderStatus::Paid, ActorRef::system());

        $days = $order->shipping_delivery_days_max;
        $order->forceFill([
            'status' => OrderStatus::Paid,
            'payment_status' => OrderPaymentStatus::Approved,
            'paid_at' => $paidAt,
            'expires_at' => null,
            'estimated_delivery_date' => $days !== null && $order->shipping_method_type !== 'pickup' ? $paidAt->addWeekdays($days)->toDateString() : null,
        ])->save();

        $history = new OrderStatusHistory(['from_status' => $from, 'to_status' => OrderStatus::Paid, 'actor_type' => ActorType::System, 'actor_id' => null, 'note' => $note]);
        $history->order_id = $order->id;
        $history->save();

        OrderPaid::dispatch($order->id, $order->number, $order->customer_id, $order->total_cents, $paidAt->toIso8601String(), $reactivated);
    }

    private function hasStockFor(Order $order): bool
    {
        $reservation = $this->reservations->forOrder($order->id);
        $variantIds = array_map(static fn ($line): int => $line->variantId, $reservation->lines);
        $this->inventory->lockForUpdate($variantIds);
        $available = $this->inventory->availability($variantIds);
        foreach ($reservation->lines as $line) {
            if (! isset($available[$line->variantId]) || $available[$line->variantId]->lessThan($line->quantity)) {
                return false;
            }
        }

        return true;
    }

    private function refund(Order $order, PaymentApproved $event, string $reason): void
    {
        $this->payments->requestRefund($order->id, Money::ofCents($event->amountCents), $reason, ActorRef::system());
        if ($order->status === OrderStatus::Cancelled) {
            $order->forceFill(['payment_status' => OrderPaymentStatus::Approved])->save();
        }
        Log::channel('payments')->warning('order.late_payment_refund', ['order_id' => $order->id, 'payment_id' => $event->paymentId, 'status' => $order->status->value]);
        LatePaymentRefundRequested::dispatch($order->id, $order->number, $order->customer_id, $event->amountCents, $reason);
    }
}
