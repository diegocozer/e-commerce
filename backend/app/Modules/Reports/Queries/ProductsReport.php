<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use App\Modules\Reports\Support\Sql;
use Illuminate\Database\Query\Builder;

/**
 * report=products (RN-REL-010): per variant and sale unit (quantities of different units are
 * never summed), billed quantity and line revenue (line total − discount) of valid sales.
 */
final class ProductsReport extends AbstractReport
{
    public function name(): string
    {
        return 'products';
    }

    public function permissions(): array
    {
        return ['reports.view', 'reports.sales'];
    }

    public function filters(): array
    {
        return ['category_id', 'brand_id'];
    }

    /** Valid sale items of the period, with catalog filters applied. */
    public static function items(AbstractReport $report, ReportQuery $query): Builder
    {
        $builder = $report->baseItems()
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->whereRaw(Sql::validSale());
        Sql::inPeriod($builder, 'o.paid_at', $query->period);

        return Sql::applyCatalogFilters($builder, 'p', $query->intFilter('category_id'), $query->intFilter('brand_id'));
    }

    public function run(ReportQuery $query): ReportResult
    {
        $rows = self::items($this, $query)
            ->select('oi.variant_id', 'oi.sale_unit')
            ->selectRaw('(array_agg(oi.sku ORDER BY oi.id DESC))[1] AS sku')
            ->selectRaw('(array_agg(oi.product_name ORDER BY oi.id DESC))[1] AS product_name')
            ->selectRaw('(array_agg(oi.variant_name ORDER BY oi.id DESC))[1] AS variant_name')
            ->selectRaw('SUM(oi.billable_quantity) AS quantity')
            ->selectRaw('SUM(oi.total_cents) AS revenue_cents')
            ->selectRaw('COUNT(DISTINCT oi.order_id) AS orders_count')
            ->groupBy('oi.variant_id', 'oi.sale_unit')
            ->orderByDesc('revenue_cents')->orderBy('oi.variant_id')
            ->limit($query->limit)
            ->get()
            ->map(fn (object $r): array => [
                'variant_id' => (int) $r->variant_id,
                'sku' => $r->sku,
                'product_name' => $r->product_name,
                'variant_name' => $r->variant_name,
                'sale_unit' => $r->sale_unit,
                'quantity' => Sql::decimal3($r->quantity),
                'revenue_cents' => (int) $r->revenue_cents,
                'orders_count' => (int) $r->orders_count,
            ])->all();

        $summary = self::items($this, $query)
            ->selectRaw('COUNT(DISTINCT oi.variant_id) AS distinct_variants, COALESCE(SUM(oi.total_cents), 0) AS revenue_cents')
            ->first();

        return new ReportResult(
            ['distinct_variants' => Sql::int($summary?->distinct_variants), 'revenue_cents' => Sql::int($summary?->revenue_cents)],
            $rows,
            ['revenue_cents' => Sql::int($summary?->revenue_cents)],
        );
    }

    public function csvColumns(): array
    {
        return [
            'sku' => ['SKU', 'text'],
            'product_name' => ['Produto', 'text'],
            'variant_name' => ['Variante', 'text'],
            'sale_unit' => ['Unidade de venda', 'text'],
            'quantity' => ['Quantidade', 'decimal3'],
            'revenue_cents' => ['Receita (R$)', 'money'],
            'orders_count' => ['Pedidos', 'int'],
        ];
    }
}
