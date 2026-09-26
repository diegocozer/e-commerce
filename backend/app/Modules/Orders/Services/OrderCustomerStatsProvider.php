<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Customers\Contracts\CustomerStatsProvider;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Order statistics of customers (BE-ORD-11). */
final class OrderCustomerStatsProvider implements CustomerStatsProvider
{
    public function statsFor(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $rows = DB::table('orders')
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')
            ->get([
                'customer_id',
                DB::raw('count(*) as orders_count'),
                DB::raw("coalesce(sum(total_cents) filter (where payment_status = 'approved'), 0) as paid_total_cents"),
                DB::raw('max(placed_at) as last_order_at'),
            ])->keyBy('customer_id');

        $stats = [];
        foreach ($customerIds as $id) {
            $row = $rows->get($id);
            $stats[$id] = [
                'orders_count' => (int) ($row->orders_count ?? 0),
                'paid_total_cents' => (int) ($row->paid_total_cents ?? 0),
                'last_order_at' => isset($row->last_order_at) ? CarbonImmutable::parse($row->last_order_at)->utc()->toIso8601ZuluString() : null,
            ];
        }

        return $stats;
    }

    public function hasOrdersInProgress(int $customerId): bool
    {
        return Order::query()->where('customer_id', $customerId)
            ->whereIn('status', [OrderStatus::PendingPayment->value, OrderStatus::Paid->value, OrderStatus::Processing->value,
                OrderStatus::Shipped->value, OrderStatus::ReadyForPickup->value])
            ->exists();
    }

    public function hasPaidOrders(int $customerId): bool
    {
        return Order::query()->where('customer_id', $customerId)->whereNotNull('paid_at')->exists()
            || Order::query()->where('customer_id', $customerId)->where('payment_status', OrderPaymentStatus::Approved->value)->exists();
    }
}
