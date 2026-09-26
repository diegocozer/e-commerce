<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\Order;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Illuminate\Support\Facades\DB;

/** PATCH /admin/orders/{id}: internal notes and tracking only. */
final class UpdateOrderDetails
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{internal_notes?: ?string, tracking_code?: ?string, tracking_url?: ?string} $data */
    public function execute(Order $order, array $data, ActorRef $actor): Order
    {
        return DB::transaction(function () use ($order, $data, $actor): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $before = array_intersect_key($locked->only(['internal_notes', 'tracking_code', 'tracking_url']), $data);
            $locked->fill($data)->save();
            $this->audit->record(AuditEntry::diff($actor, 'order.updated', 'order', $locked->id, $before,
                array_intersect_key($locked->only(['internal_notes', 'tracking_code', 'tracking_url']), $data)));

            return $locked;
        });
    }
}
