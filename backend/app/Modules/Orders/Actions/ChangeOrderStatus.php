<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\DTOs\StatusChangeData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Operational status change by the panel (ARCHITECTURE.md §4.6, API.md §3.G.9). */
final class ChangeOrderStatus
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Order $order, OrderStatus $to, ActorRef $actor, ?StatusChangeData $data = null): Order
    {
        $data ??= new StatusChangeData;

        return DB::transaction(function () use ($order, $to, $actor, $data): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $locked->status;

            if (! in_array($to, OrderStateMachine::OPERATIONAL, true)
                || ! $this->machine->canTransition($from, $to, $actor, $locked->shipping_method_type)) {
                throw InvalidOrderTransition::between($from, $to, $this->machine->operationalTransitions($from, $locked->shipping_method_type));
            }

            $now = CarbonImmutable::now();
            $changes = match ($to) {
                OrderStatus::Processing => ['processing_at' => $now],
                OrderStatus::Shipped => array_filter([
                    'shipped_at' => $now,
                    'tracking_code' => $data->trackingCode,
                    'tracking_url' => $data->trackingUrl,
                ], static fn (mixed $v): bool => $v !== null),
                OrderStatus::ReadyForPickup => ['ready_for_pickup_at' => $now],
                OrderStatus::Delivered => ['delivered_at' => $now],
                OrderStatus::PickedUp => [
                    'picked_up_at' => $now,
                    'picked_up_by_name' => $data->pickedUpByName,
                    'picked_up_by_document' => $data->pickedUpByDocument,
                ],
                default => [],
            };
            $locked->forceFill(['status' => $to, ...$changes])->save();

            $note = $data->note;
            if ($to === OrderStatus::Shipped && $data->carrierName !== null) {
                $note = trim('Transportadora: '.$data->carrierName.'. '.($note ?? ''));
            }
            $history = new OrderStatusHistory([
                'from_status' => $from, 'to_status' => $to, 'actor_type' => $actor->type, 'actor_id' => $actor->id,
                'note' => $note !== null ? mb_substr($note, 0, 1000) : null,
            ]);
            $history->order_id = $locked->id;
            $history->save();

            $this->audit->record(new AuditEntry($actor, 'order.status_changed', 'order', $locked->id,
                ['status' => $from->value], ['status' => $to->value, ...array_filter(['tracking_code' => $data->trackingCode])]));

            OrderStatusChanged::dispatch($locked->id, $from->value, $to->value, (string) $actor, $locked->tracking_code);

            return $locked;
        }, 2);
    }
}
