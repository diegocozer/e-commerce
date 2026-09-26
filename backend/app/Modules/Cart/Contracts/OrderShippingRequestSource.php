<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Shipping\DTOs\CartLineLogisticsInput;

/**
 * Logistics of an existing order for the admin shipping simulator (`order_id` mode,
 * IMPLEMENTATION_PLAN.md §5.6 / R-03). Interface by B-C, implemented in Checkout (BE-CHK-08).
 * While unbound the simulator answers 422 errors.order_id "Indisponível".
 */
interface OrderShippingRequestSource
{
    /**
     * @return array{lines: list<CartLineLogisticsInput>, subtotal_cents: int, postal_code: string}|null null = order not found
     */
    public function forOrder(int $orderId): ?array;
}
