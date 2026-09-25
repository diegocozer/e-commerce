<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Refund definitively failed (after the retries) — alert finance. */
final class PaymentRefundFailed
{
    use Dispatchable;

    public function __construct(
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly int $refundId,
        public readonly string $reasonCode,
        public readonly int $attempts,
    ) {}
}
