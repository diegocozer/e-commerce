<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use App\Modules\Reports\Support\Sql;

/** report=coupons (RN-REL-009): coupon usage in valid sales (order snapshot, no Pricing import). */
final class CouponsReport extends AbstractReport
{
    public function name(): string
    {
        return 'coupons';
    }

    public function run(ReportQuery $query): ReportResult
    {
        $base = fn () => Sql::inPeriod($this->table('orders as o'), 'o.paid_at', $query->period)
            ->whereRaw(Sql::validSale())->whereNotNull('o.coupon_id');

        $rows = $base()
            ->select('o.coupon_id')
            ->selectRaw('(array_agg(o.coupon_code ORDER BY o.id DESC))[1] AS code')
            ->selectRaw('COUNT(*) AS uses')
            ->selectRaw('SUM(o.discount_cents + o.shipping_discount_cents) AS discount_cents')
            ->selectRaw('SUM(o.total_cents) AS orders_revenue_cents')
            ->groupBy('o.coupon_id')
            ->orderByDesc('uses')->orderByDesc('discount_cents')->orderBy('o.coupon_id')
            ->limit($query->limit)
            ->get()
            ->map(fn (object $r): array => [
                'coupon_id' => (int) $r->coupon_id,
                'code' => $r->code,
                'uses' => (int) $r->uses,
                'discount_cents' => (int) $r->discount_cents,
                'orders_revenue_cents' => (int) $r->orders_revenue_cents,
            ])->all();

        $s = $base()->selectRaw('COUNT(*) AS uses, COALESCE(SUM(o.discount_cents + o.shipping_discount_cents), 0) AS discount_cents, COALESCE(SUM(o.total_cents), 0) AS revenue')->first();
        $summary = ['uses' => Sql::int($s?->uses), 'discount_cents' => Sql::int($s?->discount_cents)];

        return new ReportResult($summary, $rows, [...$summary, 'orders_revenue_cents' => Sql::int($s?->revenue)]);
    }

    public function csvColumns(): array
    {
        return [
            'code' => ['Cupom', 'text'],
            'uses' => ['Usos', 'int'],
            'discount_cents' => ['Desconto concedido (R$)', 'money'],
            'orders_revenue_cents' => ['Faturamento dos pedidos (R$)', 'money'],
        ];
    }
}
