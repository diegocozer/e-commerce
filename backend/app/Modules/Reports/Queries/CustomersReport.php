<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use App\Modules\Reports\Support\Sql;

/**
 * report=customers: top buyers by revenue of valid sales in the period. Summary (RN-REL-016):
 * new = registered in the period; returning = ≥ 2 valid paid orders up to date_to with ≥ 1 in the
 * period; individual/company = distinct buyers of the period by type (PF × PJ).
 */
final class CustomersReport extends AbstractReport
{
    public function name(): string
    {
        return 'customers';
    }

    public function run(ReportQuery $query): ReportResult
    {
        $period = $query->period;
        $valid = Sql::validSale();

        $rows = Sql::inPeriod($this->table('orders as o'), 'o.paid_at', $period)
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->whereRaw($valid)
            ->select('c.id as customer_id', 'c.name', 'c.type')
            ->selectRaw('COUNT(*) AS orders_count, SUM(o.total_cents) AS revenue_cents, MAX(o.paid_at) AS last_order_at')
            ->groupBy('c.id', 'c.name', 'c.type')
            ->orderByDesc('revenue_cents')->orderBy('c.id')
            ->limit($query->limit)
            ->get()
            ->map(fn (object $r): array => [
                'customer_id' => (int) $r->customer_id,
                'name' => $r->name,
                'type' => $r->type,
                'orders_count' => (int) $r->orders_count,
                'revenue_cents' => (int) $r->revenue_cents,
                'last_order_at' => Sql::isoDateTime($r->last_order_at),
            ])->all();

        $new = Sql::inPeriod($this->table('customers'), 'created_at', $period)->count();

        $buyers = $this->db()->selectOne(
            "WITH buyers AS (
                SELECT o.customer_id, MAX(o.customer_type) AS type
                FROM orders o
                WHERE {$valid} AND o.paid_at >= ? AND o.paid_at < ?
                GROUP BY o.customer_id
            )
            SELECT
                COUNT(*) FILTER (WHERE b.type = 'individual') AS individual_customers,
                COUNT(*) FILTER (WHERE b.type = 'company') AS company_customers,
                COUNT(*) FILTER (WHERE (
                    SELECT COUNT(*) FROM orders o2
                    WHERE o2.customer_id = b.customer_id AND o2.payment_status = 'approved'
                      AND o2.status <> 'cancelled' AND o2.paid_at < ?
                ) >= 2) AS returning_customers
            FROM buyers b",
            [$period->startUtc(), $period->endUtcExclusive(), $period->endUtcExclusive()],
        );

        return new ReportResult(
            [
                'new_customers' => $new,
                'returning_customers' => Sql::int($buyers?->returning_customers),
                'individual_customers' => Sql::int($buyers?->individual_customers),
                'company_customers' => Sql::int($buyers?->company_customers),
            ],
            $rows,
            null,
        );
    }

    public function csvColumns(): array
    {
        return [
            'customer_id' => ['ID', 'int'],
            'name' => ['Cliente', 'text'],
            'type' => ['Tipo', 'customer_type'],
            'orders_count' => ['Pedidos', 'int'],
            'revenue_cents' => ['Faturamento (R$)', 'money'],
            'last_order_at' => ['Último pedido', 'datetime'],
        ];
    }
}
