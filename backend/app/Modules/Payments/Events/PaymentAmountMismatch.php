<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Gateway amount/currency differs from the expected amount: never approved, flagged for review. */
final class PaymentAmountMismatch
{
    use Dispatchable;

    public function __construct(
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly int $expectedCents,
        public readonly int $receivedCents,
    ) {}
}
