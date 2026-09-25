<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use App\Modules\Reports\Support\Sql;

/**
 * report=sales (RN-REL-001/002/003/005/006). Base set = orders with paid_at in the bucket.
 * orders_paid counts every paid order (refunded later included); revenue/ticket only valid sales.
 * shipping_cents = freight actually charged (shipping − shipping discount).
 */
final class SalesReport extends AbstractReport
{
    public function name(): string
    {
        return 'sales';
    }

    public function permissions(): array
    {
        return ['reports.view', 'reports.sales'];
    }

    public function supportsGrouping(): bool
    {
        return true;
    }

    public function run(ReportQuery $query): ReportResult
    {
        $period = $query->period;
        $groupBy = $period->groupBy ?? 'day';
        $valid = Sql::validSale();

        $rows = Sql::inPeriod($this->table('orders as o'), 'o.paid_at', $period)
            ->selectRaw(Sql::bucket('o.paid_at', $groupBy).' AS period_start')
            ->selectRaw('COUNT(*) AS orders_paid')
            ->selectRaw("COUNT(*) FILTER (WHERE o.payment_status = 'refunded') AS orders_refunded")
            ->selectRaw("COUNT(*) FILTER (WHERE {$valid}) AS orders_valid")
            ->selectRaw("COALESCE(SUM(o.total_cents) FILTER (WHERE {$valid}), 0) AS revenue_cents")
            ->selectRaw("COALESCE(SUM(o.subtotal_cents - o.discount_cents) FILTER (WHERE {$valid}), 0) AS products_revenue_cents")
            ->selectRaw("COALESCE(SUM(o.shipping_cents - o.shipping_discount_cents) FILTER (WHERE {$valid}), 0) AS shipping_cents")
            ->selectRaw("COALESCE(SUM(o.discount_cents) FILTER (WHERE {$valid}), 0) AS discount_cents")
            ->whereNotNull('o.paid_at')
            ->groupByRaw('1')
            ->get()
            ->keyBy(fn (object $r): string => (string) $r->period_start);

        $out = [];
        $totals = ['orders_paid' => 0, 'orders_refunded' => 0, 'orders_valid' => 0, 'revenue_cents' => 0,
            'products_revenue_cents' => 0, 'shipping_cents' => 0, 'discount_cents' => 0];
        foreach (Sql::buckets($period, $groupBy) as $date) {
            $r = $rows->get($date);
            $row = [
                'period_start' => $date,
                'orders_paid' => Sql::int($r?->orders_paid),
                'orders_refunded' => Sql::int($r?->orders_refunded),
                'revenue_cents' => Sql::int($r?->revenue_cents),
                'products_revenue_cents' => Sql::int($r?->products_revenue_cents),
                'shipping_cents' => Sql::int($r?->shipping_cents),
                'discount_cents' => Sql::int($r?->discount_cents),
                'avg_ticket_cents' => Sql::avg(Sql::int($r?->revenue_cents), Sql::int($r?->orders_valid)),
            ];
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + ($key === 'orders_valid' ? Sql::int($r?->orders_valid) : $row[$key]);
            }
            $out[] = $row;
        }

        $avg = Sql::avg($totals['revenue_cents'], $totals['orders_valid']);
        unset($totals['orders_valid']);

        return new ReportResult(
            summary: [
                'orders_paid' => $totals['orders_paid'],
                'revenue_cents' => $totals['revenue_cents'],
                'avg_ticket_cents' => $avg,
                'orders_refunded' => $totals['orders_refunded'],
            ],
            rows: $out,
            totals: [...$totals, 'avg_ticket_cents' => $avg],
        );
    }

    public function csvColumns(): array
    {
        return [
            'period_start' => ['Período', 'date'],
            'orders_paid' => ['Pedidos pagos', 'int'],
            'orders_refunded' => ['Pedidos estornados', 'int'],
            'revenue_cents' => ['Faturamento (R$)', 'money'],
            'products_revenue_cents' => ['Receita de produtos (R$)', 'money'],
            'shipping_cents' => ['Frete cobrado (R$)', 'money'],
            'discount_cents' => ['Descontos (R$)', 'money'],
            'avg_ticket_cents' => ['Ticket médio (R$)', 'money'],
        ];
    }
}
