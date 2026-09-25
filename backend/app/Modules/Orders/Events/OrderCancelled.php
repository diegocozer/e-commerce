<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Order cancelled (reason = CancelReasonCode value). */
final class OrderCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $number,
        public readonly string $reason,
        public readonly bool $wasPaid,
        public readonly string $actor,
    ) {}
}
