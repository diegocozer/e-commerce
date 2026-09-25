<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Payment approved (gateway confirmed). Orders listens synchronously inside the same transaction. */
final class PaymentApproved
{
    use Dispatchable;

    public function __construct(
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly int $amountCents,
        public readonly string $approvedAt,
        public readonly string $gateway,
    ) {}
}
