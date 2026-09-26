<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\ProductSearch;
use App\Modules\Catalog\DTOs\ProductSearchQuery;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CategoryTree;
use App\Shared\Domain\SaleUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * GET /products (API.md §3.A): filters, sort allowlist, pagination and
 * facets (each facet ignores its own filter).
 */
final class ProductListing
{
    public const array SORTS = ['relevance', 'best_selling', 'price', '-price', 'name', '-created_at'];

    public function __construct(private readonly ProductSearch $search) {}

    /**
     * @param  array<string, mixed>  $f  validated filters
     * @return array{paginator: LengthAwarePaginator, facets: array<string, mixed>, search: array<string, mixed>|null}
     */
    public function run(array $f): array
    {
        $q = isset($f['q']) ? trim((string) $f['q']) : null;
        $searchResult = null;
        if ($q !== null && $q !== '') {
            $searchResult = $this->search->search(new ProductSearchQuery($q, limit: 2000));
        }
        $searchIds = $searchResult?->productIds;

        $sort = $f['sort'] ?? ($searchIds !== null ? 'relevance' : 'best_selling');
        if ($sort === 'relevance' && $searchIds === null) {
            $sort = 'best_selling';
        }

        $query = $this->filtered($f, $searchIds);
        $this->applySort($query, $sort, $searchIds);

        $perPage = (int) ($f['per_page'] ?? 24);
        $paginator = $query
            ->with([
                'variants' => fn ($v) => $v->where('is_active', true),
                'variants.product',
                'images' => fn ($i) => $i->limit(50),
                'brand', 'primaryCategory', 'categories',
            ])
            ->paginate($perPage)
            ->withQueryString();

        $search = null;
        if ($searchResult !== null) {
            $exact = null;
            if ($searchResult->exactSkuMatch !== null) {
                $p = Product::query()->with('primaryCategory')->find($searchResult->exactSkuMatch['product_id']);
                if ($p !== null) {
                    $exact = [
                        'variant_id' => $searchResult->exactSkuMatch['variant_id'],
                        'sku' => $searchResult->exactSkuMatch['sku'],
                        'product_slug' => $p->slug,
                        'url_path' => '/'.$p->primaryCategory?->slug.'/'.$p->slug,
                    ];
                }
            }
            $search = ['q' => $q, 'exact_sku_match' => $exact];
        }

        return ['paginator' => $paginator, 'facets' => $this->facets($f, $searchIds), 'search' => $search];
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  list<int>|null  $searchIds
     * @return Builder<Product>
     */
    public function filtered(array $f, ?array $searchIds, ?string $except = null): Builder
    {
        $query = Product::query()->select('products.*')
            ->where('products.is_active', true)
            ->whereHas('variants', fn ($v) => $v->where('is_active', true))
            ->whereHas('primaryCategory', fn ($c) => $c->where('is_active', true));

        if ($searchIds !== null) {
            $query->whereIn('products.id', $searchIds === [] ? [0] : $searchIds);
        }
        if ($except !== 'category' && ! empty($f['category'])) {
            $ids = $this->categoryIdsFromSlugs($f['category']);
            $query->whereExists(fn (QueryBuilder $e) => $e->from('product_categories as fpc')
                ->whereColumn('fpc.product_id', 'products.id')->whereIn('fpc.category_id', $ids === [] ? [0] : $ids));
        }
        if ($except !== 'brand' && ! empty($f['brand'])) {
            $query->whereIn('products.brand_id', DB::table('brands')->whereNull('deleted_at')->whereIn('slug', $f['brand'])->select('id'));
        }
        if ($except !== 'sale_unit' && ! empty($f['sale_unit'])) {
            $query->whereIn('products.sale_unit', $f['sale_unit']);
        }
        if ($except !== 'price' && (isset($f['price_min_cents']) || isset($f['price_max_cents']))) {
            $query->whereExists(function (QueryBuilder $e) use ($f): void {
                $e->from('product_variants as fpv')->whereColumn('fpv.product_id', 'products.id')
                    ->whereNull('fpv.deleted_at')->where('fpv.is_active', true);
                if (isset($f['price_min_cents'])) {
                    $e->where('fpv.price_cents', '>=', (int) $f['price_min_cents']);
                }
                if (isset($f['price_max_cents'])) {
                    $e->where('fpv.price_cents', '<=', (int) $f['price_max_cents']);
                }
            });
        }
        if (! empty($f['featured'])) {
            $query->where('products.is_featured', true);
        }
        if (! empty($f['in_stock'])) {
            $query->whereExists(fn (QueryBuilder $e) => $e->from('product_variants as spv')
                ->join('inventory as si', 'si.variant_id', '=', 'spv.id')
                ->whereColumn('spv.product_id', 'products.id')
                ->whereNull('spv.deleted_at')->where('spv.is_active', true)
                ->whereRaw("(si.on_hand - si.reserved) >= (CASE WHEN products.sale_unit = 'SQUARE_METER' THEN 0.001 ELSE products.min_quantity END)"));
        }
        if (! empty($f['on_sale'])) {
            $this->applyOnSale($query);
        }

        return $query;
    }

    /** @param  Builder<Product>  $query */
    private function applyOnSale(Builder $query): void
    {
        $now = now();
        $promotions = DB::table('promotions')->whereNull('deleted_at')->where('is_active', true)
            ->where('starts_at', '<=', $now)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->get(['id', 'scope']);
        $storeWide = $promotions->contains(fn ($p) => $p->scope === 'all');
        $ids = $promotions->pluck('id')->all();

        $query->where(function (Builder $w) use ($now, $storeWide, $ids): void {
            $w->whereExists(fn (QueryBuilder $e) => $e->from('product_variants as opv')
                ->whereColumn('opv.product_id', 'products.id')->whereNull('opv.deleted_at')->where('opv.is_active', true)
                ->whereNotNull('opv.promo_price_cents')
                ->where(fn ($q) => $q->whereNull('opv.promo_starts_at')->orWhere('opv.promo_starts_at', '<=', $now))
                ->where(fn ($q) => $q->whereNull('opv.promo_ends_at')->orWhere('opv.promo_ends_at', '>', $now)));
            if ($storeWide) {
                $w->orWhereRaw('true');

                return;
            }
            if ($ids === []) {
                return;
            }
            $categoryIds = [];
            $tree = app(CategoryTree::class);
            foreach (DB::table('promotion_categories')->whereIn('promotion_id', $ids)->pluck('category_id') as $cid) {
                $categoryIds = [...$categoryIds, ...$tree->descendantIds((int) $cid)];
            }
            $w->orWhereIn('products.id', DB::table('promotion_products')->whereIn('promotion_id', $ids)->select('product_id'))
                ->orWhereIn('products.brand_id', DB::table('promotion_brands')->whereIn('promotion_id', $ids)->select('brand_id'));
            if ($categoryIds !== []) {
                $w->orWhereExists(fn (QueryBuilder $e) => $e->from('product_categories as opc')
                    ->whereColumn('opc.product_id', 'products.id')->whereIn('opc.category_id', array_values(array_unique($categoryIds))));
            }
        });
    }

    /**
     * @param  Builder<Product>  $query
     * @param  list<int>|null  $searchIds
     */
    private function applySort(Builder $query, string $sort, ?array $searchIds): void
    {
        $minPrice = '(SELECT min(xv.price_cents) FROM product_variants xv WHERE xv.product_id = products.id AND xv.deleted_at IS NULL AND xv.is_active)';
        match ($sort) {
            'relevance' => $query->orderByRaw('array_position(ARRAY['.implode(',', array_map('intval', $searchIds ?: [0])).']::bigint[], products.id)'),
            'price' => $query->orderByRaw("{$minPrice} ASC"),
            '-price' => $query->orderByRaw("{$minPrice} DESC"),
            'name' => $query->orderBy('products.name'),
            '-created_at' => $query->orderByDesc('products.created_at'),
            // Paid units in the last 90 days (read-only aggregate; ties: featured, newest).
            default => $query->orderByRaw("(SELECT coalesce(sum(oi.billable_quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                    WHERE oi.product_id = products.id AND o.paid_at IS NOT NULL AND o.paid_at >= now() - interval '90 days') DESC")
                ->orderByDesc('products.is_featured')->orderByDesc('products.created_at'),
        };
        $query->orderBy('products.id');
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  list<int>|null  $searchIds
     * @return array<string, mixed>
     */
    private function facets(array $f, ?array $searchIds): array
    {
        $ids = fn (?string $except) => $this->filtered($f, $searchIds, $except)->reorder()->select('products.id');

        $categories = DB::table('product_categories as pc')->join('categories as c', 'c.id', '=', 'pc.category_id')
            ->whereIn('pc.product_id', $ids('category')->toBase())->whereNull('c.deleted_at')->where('c.is_active', true)
            ->groupBy('c.id', 'c.slug', 'c.name', 'c.position')->orderBy('c.position')->orderBy('c.name')
            ->get(['c.slug', 'c.name', DB::raw('count(DISTINCT pc.product_id) as count')]);
        $brands = DB::table('products as bp')->join('brands as b', 'b.id', '=', 'bp.brand_id')
            ->whereIn('bp.id', $ids('brand')->toBase())->whereNull('b.deleted_at')->where('b.is_active', true)
            ->groupBy('b.id', 'b.slug', 'b.name')->orderBy('b.name')
            ->get(['b.slug', 'b.name', DB::raw('count(*) as count')]);
        $units = DB::table('products as up')->whereIn('up.id', $ids('sale_unit')->toBase())
            ->groupBy('up.sale_unit')->get(['up.sale_unit', DB::raw('count(*) as count')]);
        $range = DB::table('product_variants as rv')->whereIn('rv.product_id', $ids('price')->toBase())
            ->whereNull('rv.deleted_at')->where('rv.is_active', true)
            ->selectRaw('min(rv.price_cents) as min, max(rv.price_cents) as max')->first();

        return [
            'categories' => $categories->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name, 'count' => (int) $c->count])->values()->all(),
            'brands' => $brands->map(fn ($b) => ['slug' => $b->slug, 'name' => $b->name, 'count' => (int) $b->count])->values()->all(),
            'sale_units' => $units->map(fn ($u) => ['value' => $u->sale_unit, 'label' => SaleUnit::from($u->sale_unit)->label(), 'count' => (int) $u->count])->values()->all(),
            'price_range_cents' => $range !== null && $range->min !== null ? ['min' => (int) $range->min, 'max' => (int) $range->max] : null,
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return list<int> matching active categories plus descendants
     */
    private function categoryIdsFromSlugs(array $slugs): array
    {
        $tree = app(CategoryTree::class);
        $ids = [];
        foreach (DB::table('categories')->whereNull('deleted_at')->whereIn('slug', $slugs)->pluck('id') as $id) {
            $ids = [...$ids, ...$tree->descendantIds((int) $id)];
        }

        return array_values(array_unique($ids));
    }
}
