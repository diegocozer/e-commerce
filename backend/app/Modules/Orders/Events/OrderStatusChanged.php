<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Operational status change (processing, shipped, ready_for_pickup, delivered, picked_up). $actor = ActorRef string. */
final class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $actor,
        public readonly ?string $trackingCode = null,
    ) {}
}
