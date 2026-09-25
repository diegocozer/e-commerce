<?php

declare(strict_types=1);

namespace App\Modules\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Payment approved for an order that cannot be reactivated (RN-PAG-012): automatic refund requested. */
final class LatePaymentRefundRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $number,
        public readonly int $customerId,
        public readonly int $amountCents,
        public readonly string $reason,
    ) {}
}
