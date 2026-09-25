<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Local pending payment created (ARCHITECTURE.md §5.2). */
final class PaymentCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly string $method,
        public readonly ?string $expiresAt,
    ) {}
}
