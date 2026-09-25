<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\ProductSearch;
use App\Modules\Catalog\DTOs\ProductSearchQuery;
use App\Modules\Catalog\DTOs\ProductSearchResult;
use Illuminate\Support\Facades\DB;

/**
 * ADR-014 / DB-06: products.search_vector (pt_unaccent: name A, brand and
 * categories B, short description C, description D, SKUs A) with prefix
 * terms, unioned with SKU prefix and an accent-insensitive name "contains".
 */
final class PostgresProductSearch implements ProductSearch
{
    public function search(ProductSearchQuery $q): ProductSearchResult
    {
        $text = trim($q->q);
        $tsQuery = self::toTsQuery($text);
        $skuPrefix = self::likeEscape(mb_strtoupper(str_replace(' ', '', $text))).'%';
        $contains = '%'.self::likeEscape($text).'%';

        $query = DB::table('products as p')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->where(function ($w) use ($tsQuery, $skuPrefix, $contains): void {
                if ($tsQuery !== null) {
                    $w->orWhereRaw("p.search_vector @@ to_tsquery('pt_unaccent', ?)", [$tsQuery]);
                }
                $w->orWhereRaw('public.f_unaccent(p.name) ILIKE public.f_unaccent(?)', [$contains])
                    ->orWhereExists(fn ($e) => $e->from('product_variants as sv')
                        ->whereColumn('sv.product_id', 'p.id')
                        ->whereNull('sv.deleted_at')
                        ->where('sv.is_active', true)
                        ->where('sv.sku', 'like', $skuPrefix));
            });
        if ($q->productIds !== null) {
            $query->whereIn('p.id', $q->productIds === [] ? [0] : $q->productIds);
        }

        $rank = $tsQuery !== null
            ? DB::raw("coalesce(ts_rank_cd(p.search_vector, to_tsquery('pt_unaccent', ".DB::getPdo()->quote($tsQuery).')), 0) as rank')
            : DB::raw('0 as rank');
        $rows = $query
            ->select(['p.id', $rank])
            ->selectRaw('(SELECT min(sv2.sku) FROM product_variants sv2 WHERE sv2.product_id = p.id AND sv2.deleted_at IS NULL AND sv2.is_active AND sv2.sku LIKE ?) as matched_sku', [$skuPrefix])
            ->orderByRaw('(CASE WHEN (SELECT min(sv3.sku) FROM product_variants sv3 WHERE sv3.product_id = p.id AND sv3.deleted_at IS NULL AND sv3.is_active AND sv3.sku LIKE ?) IS NULL THEN 1 ELSE 0 END)', [$skuPrefix])
            ->orderByDesc('rank')
            ->orderBy('p.id')
            ->limit($q->limit)
            ->get();

        $ids = [];
        $matched = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
            if ($row->matched_sku !== null) {
                $matched[(int) $row->id] = $row->matched_sku;
            }
        }

        $exact = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereNull('v.deleted_at')->where('v.is_active', true)
            ->whereNull('p.deleted_at')->where('p.is_active', true)
            ->where('v.sku', mb_strtoupper($text))
            ->first(['v.id', 'v.sku', 'v.product_id']);

        return new ProductSearchResult(
            productIds: $ids,
            total: count($ids),
            matchedSkus: $matched,
            exactSkuMatch: $exact !== null ? ['variant_id' => (int) $exact->id, 'sku' => $exact->sku, 'product_id' => (int) $exact->product_id] : null,
        );
    }

    public function index(int $productId): void
    {
        DB::statement(<<<'SQL'
            UPDATE products p SET search_vector =
                setweight(to_tsvector('pt_unaccent', coalesce(p.name, '')), 'A') ||
                setweight(to_tsvector('simple', coalesce((SELECT string_agg(v.sku || ' ' || replace(v.sku, '-', ' '), ' ') FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL), '')), 'A') ||
                setweight(to_tsvector('pt_unaccent', coalesce((SELECT b.name FROM brands b WHERE b.id = p.brand_id), '')), 'B') ||
                setweight(to_tsvector('pt_unaccent', coalesce((SELECT string_agg(c.name, ' ') FROM product_categories pc JOIN categories c ON c.id = pc.category_id WHERE pc.product_id = p.id), '')), 'B') ||
                setweight(to_tsvector('pt_unaccent', coalesce(p.short_description, '')), 'C') ||
                setweight(to_tsvector('pt_unaccent', coalesce(regexp_replace(p.description, '<[^>]+>', ' ', 'g'), '')), 'D')
            WHERE p.id = ?
            SQL, [$productId]);
    }

    public function remove(int $productId): void
    {
        DB::table('products')->where('id', $productId)->update(['search_vector' => null]);
    }

    /** "vinil bran" → "vinil:* & bran:*" (only letters/digits kept; null when empty). */
    public static function toTsQuery(string $text): ?string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);
        $terms = array_slice(array_filter($m[0], static fn (string $t): bool => $t !== ''), 0, 8);
        if ($terms === []) {
            return null;
        }

        return implode(' & ', array_map(static fn (string $t): string => $t.':*', $terms));
    }

    private static function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
