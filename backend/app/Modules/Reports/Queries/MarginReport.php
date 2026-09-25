<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use App\Modules\Reports\Support\Sql;

/**
 * report=margin: revenue of valid sales − cost. order_items has no cost snapshot, so cost =
 * stock quantity × CURRENT product_variants.cost_cents (per stock unit); null when not set.
 */
final class MarginReport extends AbstractReport
{
    public function name(): string
    {
        return 'margin';
    }

    public function filters(): array
    {
        return ['category_id', 'brand_id'];
    }

    public function run(ReportQuery $query): ReportResult
    {
        $base = fn () => ProductsReport::items($this, $query)->join('product_variants as v', 'v.id', '=', 'oi.variant_id');

        $rows = $base()
            ->select('oi.variant_id', 'oi.sale_unit')
            ->selectRaw('(array_agg(oi.sku ORDER BY oi.id DESC))[1] AS sku')
            ->selectRaw('(array_agg(oi.product_name ORDER BY oi.id DESC))[1] AS product_name')
            ->selectRaw('SUM(oi.billable_quantity) AS quantity')
            ->selectRaw('SUM(oi.total_cents) AS revenue_cents')
            ->selectRaw('ROUND(SUM(oi.stock_quantity * v.cost_cents)) AS cost_cents')
            ->selectRaw('BOOL_OR(v.cost_cents IS NULL) AS missing_cost')
            ->groupBy('oi.variant_id', 'oi.sale_unit')
            ->orderByDesc('revenue_cents')->orderBy('oi.variant_id')
            ->limit($query->limit)
            ->get()
            ->map(function (object $r): array {
                $revenue = (int) $r->revenue_cents;
                $cost = $r->missing_cost ? null : Sql::nullableInt($r->cost_cents);
                $margin = $cost === null ? null : $revenue - $cost;

                return [
                    'variant_id' => (int) $r->variant_id,
                    'sku' => $r->sku,
                    'product_name' => $r->product_name,
                    'sale_unit' => $r->sale_unit,
                    'quantity' => Sql::decimal3($r->quantity),
                    'revenue_cents' => $revenue,
                    'cost_cents' => $cost,
                    'margin_cents' => $margin,
                    'margin_bp' => $margin === null ? null : Sql::bp($margin, $revenue),
                ];
            })->all();

        $s = $base()
            ->selectRaw('COALESCE(SUM(oi.total_cents), 0) AS revenue_cents')
            ->selectRaw('COALESCE(SUM(oi.total_cents) FILTER (WHERE v.cost_cents IS NOT NULL), 0) AS covered_revenue_cents')
            ->selectRaw('COALESCE(ROUND(SUM(oi.stock_quantity * v.cost_cents)), 0) AS cost_cents')
            ->first();
        $revenue = Sql::int($s?->revenue_cents);
        $covered = Sql::int($s?->covered_revenue_cents);
        $cost = Sql::int($s?->cost_cents);

        $summary = [
            'revenue_cents' => $revenue,
            'cost_cents' => $cost,
            'margin_cents' => $covered - $cost,
            'cost_coverage_bp' => Sql::bp($covered, $revenue),
        ];

        return new ReportResult($summary, $rows, [
            'revenue_cents' => $revenue, 'cost_cents' => $cost, 'margin_cents' => $covered - $cost,
            'margin_bp' => Sql::bp($covered - $cost, $covered),
        ]);
    }

    public function csvColumns(): array
    {
        return [
            'sku' => ['SKU', 'text'],
            'product_name' => ['Produto', 'text'],
            'sale_unit' => ['Unidade de venda', 'text'],
            'quantity' => ['Quantidade', 'decimal3'],
            'revenue_cents' => ['Receita (R$)', 'money'],
            'cost_cents' => ['Custo (R$)', 'money'],
            'margin_cents' => ['Margem (R$)', 'money'],
            'margin_bp' => ['Margem (%)', 'bp'],
        ];
    }
}
