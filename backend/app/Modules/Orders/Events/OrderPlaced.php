<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Order created by the checkout (ARCHITECTURE.md §5.2). */
final class OrderPlaced
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $orderUuid,
        public readonly string $number,
        public readonly int $customerId,
        public readonly int $totalCents,
        public readonly string $paymentMethod,
    ) {}
}
