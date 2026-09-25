<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Customer asked to cancel a paid order (RN-PED-020): notify seller/finance. */
final class OrderCancellationRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $number,
        public readonly int $customerId,
    ) {}
}
