<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Events\PaymentFailed;

/** PaymentFailed (sync): payment_status = failed; the order stays pending_payment until it expires (RN-PAG-009). */
final class RecordPaymentFailure
{
    public function handle(PaymentFailed $event): void
    {
        Order::query()->whereKey($event->orderId)
            ->where('status', OrderStatus::PendingPayment->value)
            ->update(['payment_status' => OrderPaymentStatus::Failed->value, 'updated_at' => now()]);
    }
}
