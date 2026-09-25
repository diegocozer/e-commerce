<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/** Order data needed to create a charge at the gateway. */
final readonly class PaymentOrderContext
{
    public function __construct(
        public int $orderId,
        public string $orderNumber,
        public PayerData $payer,
    ) {}
}
