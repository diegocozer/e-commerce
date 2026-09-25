<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Order became paid (payment approved, or late payment reactivation when $reactivated). */
final class OrderPaid
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $number,
        public readonly int $customerId,
        public readonly int $totalCents,
        public readonly string $paidAt,
        public readonly bool $reactivated = false,
    ) {}
}
