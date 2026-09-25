<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\PaymentOrderContext;

/**
 * Inversion (ARCHITECTURE.md §2.3 rule 7): Payments needs the order number and
 * the payer to (re)create a charge in `initiate()`, but must not depend on
 * Orders. Implemented by Orders.
 */
interface PaymentOrderContextProvider
{
    public function forOrder(int $orderId): ?PaymentOrderContext;
}
