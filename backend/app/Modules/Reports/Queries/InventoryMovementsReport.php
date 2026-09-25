<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use App\Modules\Reports\Support\Sql;

/** report=inventory-movements (RN-REL-015): movements by variant and type in the period. */
final class InventoryMovementsReport extends AbstractReport
{
    public function name(): string
    {
        return 'inventory-movements';
    }

    public function permissions(): array
    {
        return ['reports.view', 'reports.inventory'];
    }

    public function run(ReportQuery $query): ReportResult
    {
        $base = fn () => Sql::inPeriod($this->table('inventory_movements as m'), 'm.created_at', $query->period);

        $rows = $base()
            ->join('product_variants as v', 'v.id', '=', 'm.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->select('m.variant_id', 'v.sku', 'p.name as product_name', 'm.type')
            ->selectRaw('COUNT(*) AS movements_count, SUM(m.quantity) AS quantity')
            ->groupBy('m.variant_id', 'v.sku', 'p.name', 'm.type')
            ->orderBy('v.sku')->orderBy('m.type')
            ->limit($query->limit)
            ->get()
            ->map(fn (object $r): array => [
                'variant_id' => (int) $r->variant_id,
                'sku' => $r->sku,
                'product_name' => $r->product_name,
                'type' => $r->type,
                'movements_count' => (int) $r->movements_count,
                'quantity' => Sql::decimal3($r->quantity),
            ])->all();

        $movements = $base()->count();

        return new ReportResult(['movements' => $movements], $rows, ['movements_count' => $movements]);
    }

    public function csvColumns(): array
    {
        return [
            'sku' => ['SKU', 'text'],
            'product_name' => ['Produto', 'text'],
            'type' => ['Tipo', 'text'],
            'movements_count' => ['Movimentos', 'int'],
            'quantity' => ['Quantidade', 'decimal3'],
        ];
    }
}
