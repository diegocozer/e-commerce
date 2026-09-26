<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Customers\Models\Customer;
use App\Modules\Notifications\Notifications\AdminAlertNotification;
use App\Modules\Notifications\Notifications\LatePaymentRefundNotification;
use App\Modules\Notifications\Notifications\OrderCancelledNotification;
use App\Modules\Notifications\Notifications\OrderDeliveredNotification;
use App\Modules\Notifications\Notifications\OrderNotification;
use App\Modules\Notifications\Notifications\OrderPaidNotification;
use App\Modules\Notifications\Notifications\OrderReadyForPickupNotification;
use App\Modules\Notifications\Notifications\OrderReceivedNotification;
use App\Modules\Notifications\Notifications\OrderShippedNotification;
use App\Modules\Notifications\Notifications\PaymentFailedNotification;
use App\Modules\Notifications\Notifications\RefundProcessedNotification;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Orders\Events\LatePaymentRefundRequested;
use App\Modules\Orders\Events\OrderCancellationRequested;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Events\PaymentAmountMismatch;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentRefunded;
use App\Modules\Payments\Events\PaymentRefundFailed;

/**
 * Maps domain events to notifications (ARCHITECTURE.md §5.2, RN-NOT). The
 * listeners only resolve recipients and push QUEUED notifications
 * (queue `notifications`, afterCommit) — nothing is sent if the
 * transaction rolls back; e-mail failures never affect the business flow.
 */
final class OrderNotificationSubscriber
{
    public function __construct(private readonly AdminNotifier $admins) {}

    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->toCustomer($event->orderId, new OrderReceivedNotification($event->orderId));
    }

    public function onOrderPaid(OrderPaid $event): void
    {
        $this->toCustomer($event->orderId, new OrderPaidNotification($event->orderId, $event->reactivated));
        $this->admins->notify(['orders.fulfill'], new AdminAlertNotification(
            $event->reactivated ? 'order_reactivated' : 'order_paid',
            ($event->reactivated ? 'Pedido reativado por pagamento tardio: ' : 'Novo pedido pago a separar: ').$event->number,
            'Total: R$ '.number_format($event->totalCents / 100, 2, ',', '.'),
            '/admin/pedidos/'.$event->orderId,
        ));
    }

    public function onPaymentFailed(PaymentFailed $event): void
    {
        $this->toCustomer($event->orderId, new PaymentFailedNotification($event->orderId));
    }

    public function onStatusChanged(OrderStatusChanged $event): void
    {
        $notification = match ($event->to) {
            'shipped' => new OrderShippedNotification($event->orderId),
            'ready_for_pickup' => new OrderReadyForPickupNotification($event->orderId),
            'delivered', 'picked_up' => new OrderDeliveredNotification($event->orderId),
            default => null, // processing: timeline only (RN-NOT)
        };
        if ($notification !== null) {
            $this->toCustomer($event->orderId, $notification);
        }
    }

    public function onOrderCancelled(OrderCancelled $event): void
    {
        $this->toCustomer($event->orderId, new OrderCancelledNotification($event->orderId, $event->reason, $event->wasPaid));
    }

    public function onPaymentRefunded(PaymentRefunded $event): void
    {
        $this->toCustomer($event->orderId, new RefundProcessedNotification($event->orderId, $event->amountCents));
    }

    public function onLatePaymentRefund(LatePaymentRefundRequested $event): void
    {
        $this->toCustomer($event->orderId, new LatePaymentRefundNotification($event->orderId));
        $this->admins->notify(['orders.cancel_paid', 'payments.reconcile'], new AdminAlertNotification(
            'late_payment_refund', 'Estorno automático de pagamento tardio: '.$event->number, $event->reason, '/admin/pedidos/'.$event->orderId,
        ), includeAlertEmails: true);
    }

    public function onCancellationRequested(OrderCancellationRequested $event): void
    {
        $this->admins->notify(['orders.cancel_paid', 'orders.cancel_unpaid'], new AdminAlertNotification(
            'cancellation_requested', 'Solicitação de cancelamento: '.$event->number, null, '/admin/pedidos/'.$event->orderId,
        ));
    }

    public function onRefundFailed(PaymentRefundFailed $event): void
    {
        $number = Order::query()->whereKey($event->orderId)->value('number');
        $this->admins->notify(['orders.cancel_paid', 'payments.reconcile'], new AdminAlertNotification(
            'refund_failed', 'Falha no estorno do pedido '.$number, 'O estorno não foi concluído no gateway após as tentativas automáticas. Trate manualmente.', '/admin/pedidos/'.$event->orderId,
        ), includeAlertEmails: true);
    }

    public function onAmountMismatch(PaymentAmountMismatch $event): void
    {
        $number = Order::query()->whereKey($event->orderId)->value('number');
        $this->admins->notify(['orders.cancel_paid', 'payments.reconcile'], new AdminAlertNotification(
            'payment_amount_mismatch', 'Divergência de valor no PIX do pedido '.$number,
            sprintf('Esperado R$ %s, recebido R$ %s. O pedido não foi aprovado.', number_format($event->expectedCents / 100, 2, ',', '.'), number_format($event->receivedCents / 100, 2, ',', '.')),
            '/admin/pedidos/'.$event->orderId,
        ), includeAlertEmails: true);
    }

    private function toCustomer(int $orderId, OrderNotification $notification): void
    {
        $customerId = Order::query()->whereKey($orderId)->value('customer_id');
        $customer = $customerId === null ? null : Customer::query()->find($customerId);
        if ($customer === null || $customer->anonymized_at !== null) {
            return;
        }
        $customer->notify($notification);
    }
}
