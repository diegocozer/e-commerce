<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use App\Modules\Reports\Support\Sql;

/**
 * report=revenue — cash view: gross = Σ total of orders paid in the bucket (payment approved at
 * that time, including orders refunded later; cancelled-unpaid never have paid_at),
 * refunds = Σ total of orders refunded in the bucket (RN-REL-004), net = gross − refunds.
 */
final class RevenueReport extends AbstractReport
{
    public function name(): string
    {
        return 'revenue';
    }

    public function supportsGrouping(): bool
    {
        return true;
    }

    public function run(ReportQuery $query): ReportResult
    {
        $period = $query->period;
        $groupBy = $period->groupBy ?? 'day';

        $paid = Sql::inPeriod($this->table('orders as o'), 'o.paid_at', $period)
            ->selectRaw(Sql::bucket('o.paid_at', $groupBy).' AS period_start')
            ->selectRaw('SUM(o.total_cents) AS gross_cents')
            ->selectRaw('SUM(o.discount_cents + o.shipping_discount_cents) AS discount_cents')
            ->selectRaw('SUM(o.shipping_cents - o.shipping_discount_cents) AS shipping_cents')
            ->whereIn('o.payment_status', ['approved', 'refunded'])
            ->groupByRaw('1')
            ->get()->keyBy(fn (object $r): string => (string) $r->period_start);

        $refunds = Sql::inPeriod($this->table('orders as o'), 'o.refunded_at', $period)
            ->selectRaw(Sql::bucket('o.refunded_at', $groupBy).' AS period_start')
            ->selectRaw('SUM(o.total_cents) AS refunds_cents')
            ->where('o.payment_status', 'refunded')
            ->groupByRaw('1')
            ->get()->keyBy(fn (object $r): string => (string) $r->period_start);

        $keys = ['gross_cents', 'discount_cents', 'shipping_cents', 'refunds_cents', 'net_cents'];
        $totals = array_fill_keys($keys, 0);
        $rows = [];
        foreach (Sql::buckets($period, $groupBy) as $date) {
            $p = $paid->get($date);
            $gross = Sql::int($p?->gross_cents);
            $refund = Sql::int($refunds->get($date)?->refunds_cents);
            $row = [
                'period_start' => $date,
                'gross_cents' => $gross,
                'discount_cents' => Sql::int($p?->discount_cents),
                'shipping_cents' => Sql::int($p?->shipping_cents),
                'refunds_cents' => $refund,
                'net_cents' => $gross - $refund,
            ];
            foreach ($keys as $key) {
                $totals[$key] += $row[$key];
            }
            $rows[] = $row;
        }

        return new ReportResult($totals, $rows, $totals);
    }

    public function csvColumns(): array
    {
        return [
            'period_start' => ['Período', 'date'],
            'gross_cents' => ['Bruto (R$)', 'money'],
            'discount_cents' => ['Descontos (R$)', 'money'],
            'shipping_cents' => ['Frete (R$)', 'money'],
            'refunds_cents' => ['Estornos (R$)', 'money'],
            'net_cents' => ['Líquido (R$)', 'money'],
        ];
    }
}
