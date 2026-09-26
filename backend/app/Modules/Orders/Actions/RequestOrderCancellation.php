<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderCancellationRequested;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Customer asks to cancel a paid order (RN-PED-020): status does not change. */
final class RequestOrderCancellation
{
    public function execute(Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, [OrderStatus::Paid, OrderStatus::Processing], true) || $locked->cancellation_requested_at !== null) {
                throw InvalidOrderTransition::because('Não é possível solicitar o cancelamento deste pedido.');
            }

            $locked->forceFill(['cancellation_requested_at' => CarbonImmutable::now(), 'cancellation_request_reason' => mb_substr($reason, 0, 500)])->save();
            OrderCancellationRequested::dispatch($locked->id, $locked->number, $locked->customer_id);

            return $locked;
        });
    }
}
