<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Refund succeeded at the gateway. */
final class PaymentRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly int $refundId,
        public readonly int $amountCents,
        public readonly string $refundedAt,
    ) {}
}
