<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Models\Order;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Illuminate\Support\Facades\DB;

/** Admin refuses the customer's cancellation request without cancelling (API.md §3.G.9). */
final class DismissCancellationRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(Order $order, string $note, ActorRef $actor): Order
    {
        return DB::transaction(function () use ($order, $note, $actor): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->cancellation_requested_at === null) {
                throw InvalidOrderTransition::because('Não há solicitação de cancelamento aberta.');
            }

            $original = $locked->cancellation_request_reason;
            $locked->forceFill(['cancellation_requested_at' => null, 'cancellation_request_reason' => null])->save();
            $this->audit->record(new AuditEntry($actor, 'order.cancellation_request_dismissed', 'order', $locked->id,
                ['cancellation_request_reason' => $original], ['note' => $note]));

            return $locked;
        });
    }
}
