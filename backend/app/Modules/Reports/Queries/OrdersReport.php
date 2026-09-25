<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use App\Modules\Reports\Support\Sql;

/**
 * report=orders (RN-REL-007/008/012/013): orders placed in the period by current status,
 * payment conversion, cancellations by reason and median paid → shipped/ready time (hours,
 * calendar time; business-hours calendar is not modelled).
 */
final class OrdersReport extends AbstractReport
{
    public function name(): string
    {
        return 'orders';
    }

    public function filters(): array
    {
        return ['status'];
    }

    public function run(ReportQuery $query): ReportResult
    {
        $period = $query->period;
        $status = $query->filter('status');

        $counts = Sql::inPeriod($this->table('orders as o'), 'o.placed_at', $period)
            ->select('o.status')->selectRaw('COUNT(*) AS count')
            ->groupBy('o.status')
            ->pluck('count', 'status');

        $rows = [];
        foreach (OrderStatus::values() as $value) {
            if ($status === null || $status === $value) {
                $rows[] = ['status' => $value, 'count' => (int) ($counts[$value] ?? 0)];
            }
        }

        $s = Sql::inPeriod($this->table('orders as o'), 'o.placed_at', $period)
            ->selectRaw('COUNT(*) AS created')
            ->selectRaw('COUNT(*) FILTER (WHERE o.paid_at IS NOT NULL) AS paid')
            ->selectRaw("COUNT(*) FILTER (WHERE o.status = 'cancelled') AS cancelled")
            ->selectRaw("COUNT(*) FILTER (WHERE o.status = 'cancelled' AND o.cancel_reason_code = 'payment_expired') AS cancelled_payment_expired")
            ->selectRaw("COUNT(*) FILTER (WHERE o.status = 'cancelled' AND o.cancel_reason_code = 'customer') AS cancelled_customer")
            ->selectRaw("COUNT(*) FILTER (WHERE o.status = 'cancelled' AND o.cancel_reason_code = 'admin') AS cancelled_admin")
            ->first();

        $median = Sql::inPeriod($this->table('orders as o'), 'o.paid_at', $period)
            ->whereRaw('COALESCE(o.shipped_at, o.ready_for_pickup_at) IS NOT NULL')
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM COALESCE(o.shipped_at, o.ready_for_pickup_at) - o.paid_at) / 3600) AS hours')
            ->value('hours');

        $created = Sql::int($s?->created);
        $cancelled = Sql::int($s?->cancelled);

        return new ReportResult(
            [
                'created' => $created,
                'paid' => Sql::int($s?->paid),
                'cancelled' => $cancelled,
                'cancelled_payment_expired' => Sql::int($s?->cancelled_payment_expired),
                'cancelled_customer' => Sql::int($s?->cancelled_customer),
                'cancelled_admin' => Sql::int($s?->cancelled_admin),
                'conversion_bp' => Sql::bp(Sql::int($s?->paid), $created),
                'cancellation_bp' => Sql::bp($cancelled, $created),
                'median_fulfillment_hours' => $median === null ? null : round((float) $median, 1),
            ],
            $rows,
            ['count' => array_sum(array_column($rows, 'count'))],
        );
    }

    public function csvColumns(): array
    {
        return [
            'status' => ['Status', 'order_status'],
            'count' => ['Pedidos', 'int'],
        ];
    }
}
