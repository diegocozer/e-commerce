<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Gateway rejected/cancelled the charge; the order stays pending_payment until it expires. */
final class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly string $reasonCode,
    ) {}
}
