<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Events\PaymentRefunded;
use Carbon\CarbonImmutable;

/** PaymentRefunded (sync, inside the refund transaction): payment_status = refunded, refunded_at (RN-PAG-010). */
final class MarkOrderAsRefunded
{
    public function handle(PaymentRefunded $event): void
    {
        $order = Order::query()->lockForUpdate()->find($event->orderId);
        if ($order === null || $order->payment_status === OrderPaymentStatus::Refunded) {
            return;
        }

        $order->forceFill([
            'payment_status' => OrderPaymentStatus::Refunded,
            'refunded_at' => CarbonImmutable::parse($event->refundedAt),
        ])->save();
    }
}
