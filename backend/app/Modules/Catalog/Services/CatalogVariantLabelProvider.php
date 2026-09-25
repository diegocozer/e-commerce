<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Support\CategoryTree;
use App\Modules\Inventory\Contracts\VariantLabelProvider;
use Illuminate\Support\Facades\DB;

/** Implements Inventory's inversion contract (ARCHITECTURE.md §2.3 rule 7). */
final class CatalogVariantLabelProvider implements VariantLabelProvider
{
    public function labels(array $variantIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $variantIds)));
        if ($ids === []) {
            return [];
        }

        $out = [];
        $rows = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('v.id', $ids)
            ->get(['v.id', 'v.sku', 'v.name', 'v.product_id', 'p.name as product_name', 'p.slug as product_slug',
                'p.is_active as product_is_active', 'p.deleted_at as product_deleted_at', 'p.sale_unit', 'p.min_quantity']);
        foreach ($rows as $r) {
            $out[(int) $r->id] = [
                'sku' => $r->sku,
                'name' => $r->name,
                'product_id' => (int) $r->product_id,
                'product_name' => $r->product_name,
                'product_slug' => $r->product_slug,
                'product_is_active' => (bool) $r->product_is_active && $r->product_deleted_at === null,
                'sale_unit' => $r->sale_unit,
                'min_quantity' => (string) $r->min_quantity,
            ];
        }

        return $out;
    }

    public function search(array $filters): array
    {
        $query = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->orderBy('v.sku');

        if (empty($filters['include_deleted'])) {
            $query->whereNull('v.deleted_at')->whereNull('p.deleted_at');
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtoupper($q)).'%';
            $query->where(fn ($w) => $w->where('v.sku', 'like', $prefix)
                ->orWhereRaw('public.f_unaccent(v.name) ILIKE public.f_unaccent(?)', [$like])
                ->orWhereRaw('public.f_unaccent(p.name) ILIKE public.f_unaccent(?)', [$like]));
        }
        if (! empty($filters['category_id'])) {
            $categoryIds = app(CategoryTree::class)->descendantIds((int) $filters['category_id']);
            $query->whereExists(fn ($e) => $e->from('product_categories as pc')
                ->whereColumn('pc.product_id', 'p.id')
                ->whereIn('pc.category_id', $categoryIds));
        }
        if (! empty($filters['brand_id'])) {
            $query->where('p.brand_id', (int) $filters['brand_id']);
        }
        if (! empty($filters['sale_unit'])) {
            $query->whereIn('p.sale_unit', (array) $filters['sale_unit']);
        }
        if (array_key_exists('product_active', $filters) && $filters['product_active'] !== null) {
            $query->where('p.is_active', (bool) $filters['product_active']);
        }

        return $query->pluck('v.id')->map(fn ($id): int => (int) $id)->all();
    }
}
