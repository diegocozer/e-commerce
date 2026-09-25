<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

/**
 * Order statistics of customers (inversion: implemented by Orders, BE-ORD-11).
 * Customers registers NullCustomerStatsProvider until then.
 */
interface CustomerStatsProvider
{
    /**
     * @param  list<int>  $customerIds
     * @return array<int, array{orders_count:int, paid_total_cents:int, last_order_at:?string}>
     */
    public function statsFor(array $customerIds): array;

    /** Blocks anonymization (409 resource_in_use). */
    public function hasOrdersInProgress(int $customerId): bool;

    /** Blocks CPF/CNPJ correction. */
    public function hasPaidOrders(int $customerId): bool;
}
