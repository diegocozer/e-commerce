<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\CustomerStatsProvider;

/** Default until Orders binds the real provider (BE-ORD-11). */
final class NullCustomerStatsProvider implements CustomerStatsProvider
{
    public function statsFor(array $customerIds): array
    {
        $stats = [];
        foreach ($customerIds as $id) {
            $stats[$id] = ['orders_count' => 0, 'paid_total_cents' => 0, 'last_order_at' => null];
        }

        return $stats;
    }

    public function hasOrdersInProgress(int $customerId): bool
    {
        return false;
    }

    public function hasPaidOrders(int $customerId): bool
    {
        return false;
    }
}
