<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use App\Modules\Reports\Support\Sql;

/**
 * report=shipping (RN-REL-017): valid sales by shipping method snapshot and destination city.
 * shipping_revenue = freight charged (shipping − shipping discount); free shipping = non-pickup
 * order with zero charged freight.
 */
final class ShippingReport extends AbstractReport
{
    private const string FREE = "o.shipping_method_type <> 'pickup' AND o.shipping_cents - o.shipping_discount_cents = 0";

    public function name(): string
    {
        return 'shipping';
    }

    public function filters(): array
    {
        return ['shipping_method_id'];
    }

    public function run(ReportQuery $query): ReportResult
    {
        $free = self::FREE;
        $base = function () use ($query) {
            $b = Sql::inPeriod($this->table('orders as o'), 'o.paid_at', $query->period)->whereRaw(Sql::validSale());
            if (($methodId = $query->intFilter('shipping_method_id')) !== null) {
                $b->where('o.shipping_method_id', $methodId);
            }

            return $b;
        };

        $rows = $base()
            ->select('o.shipping_method_id', 'o.shipping_method_type as method_type', 'o.shipping_city as city', 'o.shipping_state as state')
            ->selectRaw('(array_agg(o.shipping_method_name ORDER BY o.id DESC))[1] AS method_name')
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('SUM(o.shipping_cents - o.shipping_discount_cents) AS shipping_revenue_cents')
            ->selectRaw("COUNT(*) FILTER (WHERE {$free}) AS free_shipping_orders")
            ->selectRaw('SUM(o.shipping_discount_cents) AS shipping_discount_cents')
            ->groupBy('o.shipping_method_id', 'o.shipping_method_type', 'o.shipping_city', 'o.shipping_state')
            ->orderByDesc('orders_count')->orderBy('o.shipping_method_id')->orderBy('o.shipping_city')
            ->limit($query->limit)
            ->get()
            ->map(fn (object $r): array => [
                'shipping_method_id' => Sql::nullableInt($r->shipping_method_id),
                'method_name' => $r->method_name,
                'method_type' => $r->method_type,
                'city' => $r->city,
                'state' => $r->state,
                'orders_count' => (int) $r->orders_count,
                'shipping_revenue_cents' => (int) $r->shipping_revenue_cents,
                'free_shipping_orders' => (int) $r->free_shipping_orders,
                'shipping_discount_cents' => (int) $r->shipping_discount_cents,
            ])->all();

        $s = $base()
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(o.shipping_cents - o.shipping_discount_cents), 0) AS revenue')
            ->selectRaw("COUNT(*) FILTER (WHERE {$free}) AS free, COALESCE(SUM(o.shipping_discount_cents), 0) AS discount")
            ->selectRaw("COUNT(*) FILTER (WHERE o.shipping_method_type <> 'pickup') AS delivered")
            ->first();
        $orders = Sql::int($s?->orders);
        $revenue = Sql::int($s?->revenue);
        $delivered = Sql::int($s?->delivered);

        return new ReportResult(
            [
                'orders' => $orders,
                'shipping_revenue_cents' => $revenue,
                'free_shipping_orders' => Sql::int($s?->free),
                'avg_shipping_cents' => Sql::avg($revenue, $delivered),
                'free_shipping_bp' => Sql::bp(Sql::int($s?->free), $delivered),
            ],
            $rows,
            [
                'orders_count' => $orders, 'shipping_revenue_cents' => $revenue,
                'free_shipping_orders' => Sql::int($s?->free), 'shipping_discount_cents' => Sql::int($s?->discount),
            ],
        );
    }

    public function csvColumns(): array
    {
        return [
            'method_name' => ['Método', 'text'],
            'method_type' => ['Tipo', 'text'],
            'city' => ['Cidade', 'text'],
            'state' => ['UF', 'text'],
            'orders_count' => ['Pedidos', 'int'],
            'shipping_revenue_cents' => ['Frete cobrado (R$)', 'money'],
            'free_shipping_orders' => ['Pedidos com frete grátis', 'int'],
            'shipping_discount_cents' => ['Desconto de frete (R$)', 'money'],
        ];
    }
}
