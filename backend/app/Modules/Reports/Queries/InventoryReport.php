<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use App\Modules\Reports\Support\Sql;

/**
 * report=inventory: current stock position of non-deleted variants (RN-REL-014), valuation
 * on_hand × cost_cents (cost per stock unit) and stock quantity sold (valid sales) in the period.
 */
final class InventoryReport extends AbstractReport
{
    public function name(): string
    {
        return 'inventory';
    }

    public function permissions(): array
    {
        return ['reports.view', 'reports.inventory'];
    }

    public function requiresPeriod(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return ['category_id', 'brand_id'];
    }

    public function run(ReportQuery $query): ReportResult
    {
        $period = $query->period;
        $threshold = Sql::effectiveThreshold('i');

        $sold = $this->table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereRaw(Sql::validSale())
            ->where('o.paid_at', '>=', $period->startUtc())->where('o.paid_at', '<', $period->endUtcExclusive())
            ->groupBy('oi.variant_id')
            ->select('oi.variant_id')->selectRaw('SUM(oi.stock_quantity) AS sold_quantity');

        $base = $this->table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('inventory as i', 'i.variant_id', '=', 'v.id')
            ->leftJoinSub($sold, 's', 's.variant_id', '=', 'v.id')
            ->whereNull('v.deleted_at')->whereNull('p.deleted_at');
        Sql::applyCatalogFilters($base, 'p', $query->intFilter('category_id'), $query->intFilter('brand_id'));

        $rows = $base
            ->select('v.id as variant_id', 'v.sku', 'p.name as product_name', 'p.sale_unit', 'v.cost_cents')
            ->selectRaw('COALESCE(i.on_hand, 0) AS on_hand, COALESCE(i.reserved, 0) AS reserved')
            ->selectRaw("{$threshold} AS threshold")
            ->selectRaw("(COALESCE(i.on_hand, 0) - COALESCE(i.reserved, 0)) <= {$threshold} AS is_low_stock")
            ->selectRaw('ROUND(COALESCE(i.on_hand, 0) * v.cost_cents) AS stock_value_cents')
            ->selectRaw('COALESCE(s.sold_quantity, 0) AS sold_quantity')
            ->orderByRaw('is_low_stock DESC, p.name, v.sku')
            ->limit($query->limit)
            ->get()
            ->map(fn (object $r): array => [
                'variant_id' => (int) $r->variant_id,
                'sku' => $r->sku,
                'product_name' => $r->product_name,
                'sale_unit' => $r->sale_unit,
                'on_hand' => Sql::decimal3($r->on_hand),
                'reserved' => Sql::decimal3($r->reserved),
                'available' => Sql::decimal3((float) $r->on_hand - (float) $r->reserved),
                'low_stock_threshold' => Sql::decimal3($r->threshold),
                'is_low_stock' => (bool) $r->is_low_stock,
                'cost_cents' => Sql::nullableInt($r->cost_cents),
                'stock_value_cents' => Sql::nullableInt($r->stock_value_cents),
                'sold_quantity' => Sql::decimal3($r->sold_quantity),
            ])->all();

        $summaryQuery = $this->table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('inventory as i', 'i.variant_id', '=', 'v.id')
            ->whereNull('v.deleted_at')->whereNull('p.deleted_at');
        Sql::applyCatalogFilters($summaryQuery, 'p', $query->intFilter('category_id'), $query->intFilter('brand_id'));
        $s = $summaryQuery
            ->selectRaw('COUNT(*) AS variants')
            ->selectRaw("COUNT(*) FILTER (WHERE (COALESCE(i.on_hand, 0) - COALESCE(i.reserved, 0)) <= {$threshold}) AS low")
            ->selectRaw('ROUND(SUM(COALESCE(i.on_hand, 0) * v.cost_cents)) AS stock_value')
            ->first();

        return new ReportResult(
            [
                'variants' => Sql::int($s?->variants),
                'low_stock_variants' => Sql::int($s?->low),
                'stock_value_cents' => Sql::nullableInt($s?->stock_value),
            ],
            $rows,
            ['stock_value_cents' => Sql::nullableInt($s?->stock_value)],
        );
    }

    public function csvColumns(): array
    {
        return [
            'sku' => ['SKU', 'text'],
            'product_name' => ['Produto', 'text'],
            'sale_unit' => ['Unidade de venda', 'text'],
            'on_hand' => ['Em estoque', 'decimal3'],
            'reserved' => ['Reservado', 'decimal3'],
            'available' => ['Disponível', 'decimal3'],
            'low_stock_threshold' => ['Estoque mínimo', 'decimal3'],
            'is_low_stock' => ['Estoque baixo', 'bool'],
            'cost_cents' => ['Custo unitário (R$)', 'money'],
            'stock_value_cents' => ['Valor em estoque (R$)', 'money'],
            'sold_quantity' => ['Vendido no período', 'decimal3'],
        ];
    }
}
