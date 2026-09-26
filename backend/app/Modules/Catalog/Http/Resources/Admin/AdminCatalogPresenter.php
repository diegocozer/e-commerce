<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\Admin;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductActivation;
use App\Modules\Catalog\Support\CategoryTree;
use App\Modules\Catalog\Support\ImageUrls;
use App\Modules\Inventory\Contracts\InventoryRecords;
use App\Shared\Domain\Quantity;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Admin JSON shapes of API.md §2.13 (catalog part). */
final class AdminCatalogPresenter
{
    public static function ts(?DateTimeInterface $d): ?string
    {
        return $d === null ? null : \Carbon\CarbonImmutable::instance($d)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function disk(): string
    {
        return (string) config('catalog.images_disk', 'public');
    }

    /**
     * @param  Collection<int, Category>  $all  categories to render (tree built from parent_id)
     * @return list<array<string, mixed>>
     */
    public static function categoryTree(Collection $all): array
    {
        $counts = self::categoryProductCounts($all->pluck('id')->all());
        $byParent = $all->groupBy(fn (Category $c) => $c->parent_id ?? 0);
        $build = function (int $parent, int $depth) use (&$build, $byParent, $counts): array {
            return ($byParent[$parent] ?? collect())->sortBy([['position', 'asc'], ['id', 'asc']])
                ->map(fn (Category $c) => self::category($c, $depth, $counts[$c->id] ?? 0, $depth < 3 ? $build($c->id, $depth + 1) : []))
                ->values()->all();
        };

        return $build(0, 1);
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    public static function category(Category $c, ?int $depth = null, ?int $productsCount = null, array $children = []): array
    {
        return [
            'id' => $c->id,
            'parent_id' => $c->parent_id,
            'name' => $c->name,
            'slug' => $c->slug,
            'description_html' => $c->description,
            'image_url' => ImageUrls::url(self::disk(), $c->image_path),
            'meta_title' => $c->meta_title,
            'meta_description' => $c->meta_description,
            'position' => $c->position,
            'is_active' => $c->is_active,
            'depth' => $depth ?? app(CategoryTree::class)->depth($c->id),
            'products_count' => $productsCount ?? (self::categoryProductCounts([$c->id])[$c->id] ?? 0),
            'children' => $children,
            'created_at' => self::ts($c->created_at),
            'updated_at' => self::ts($c->updated_at),
            'deleted_at' => self::ts($c->deleted_at),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private static function categoryProductCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('product_categories as pc')->join('products as p', 'p.id', '=', 'pc.product_id')
            ->whereIn('pc.category_id', $ids)->where('p.is_active', true)->whereNull('p.deleted_at')
            ->groupBy('pc.category_id')->selectRaw('pc.category_id, count(*) as c')->pluck('c', 'category_id')
            ->mapWithKeys(fn ($c, $id) => [(int) $id => (int) $c])->all();
    }

    /** @return array<string, mixed> */
    public static function brand(Brand $b, ?int $productsCount = null): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'slug' => $b->slug,
            'logo_url' => ImageUrls::url(self::disk(), $b->logo_path),
            'is_active' => $b->is_active,
            'products_count' => $productsCount ?? (int) DB::table('products')->where('brand_id', $b->id)->whereNull('deleted_at')->count(),
            'created_at' => self::ts($b->created_at),
            'updated_at' => self::ts($b->updated_at),
            'deleted_at' => self::ts($b->deleted_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function image(ProductImage $img, string $productName): array
    {
        return [
            'id' => $img->id,
            'urls' => ImageUrls::for($img->disk, $img->path, $img->width_px),
            'alt' => $img->alt !== null && $img->alt !== '' ? $img->alt : $productName,
            'width' => $img->width_px,
            'height' => $img->height_px,
            'position' => $img->position,
            'variant_id' => $img->variant_id,
            'original_filename' => null,
            'created_at' => self::ts($img->created_at),
        ];
    }

    /** @return array<string, mixed> AdminProduct */
    public static function product(Product $p): array
    {
        $p->loadMissing(['variants', 'images', 'categories', 'primaryCategory']);
        $variants = $p->variants;
        $inventory = DB::table('inventory')->whereIn('variant_id', $variants->pluck('id'))->get()->keyBy('variant_id');
        $ordered = DB::table('order_items')->whereIn('variant_id', $variants->pluck('id'))->distinct()->pluck('variant_id')->map(fn ($i) => (int) $i)->all();
        $default = app(InventoryRecords::class)->defaultLowStockThreshold();
        $admin = Auth::guard('admin')->user();
        $showCost = $admin !== null && ($admin->can('prices.manage') || $admin->can('reports.view'));
        $m = static fn (?int $mm) => $mm !== null ? Quantity::fromMilli($mm)->toNumber() : null;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'url_path' => '/'.$p->primaryCategory?->slug.'/'.$p->slug,
            'short_description' => $p->short_description,
            'description_html' => $p->description,
            'specifications' => array_values($p->specifications ?? []),
            'sale_unit' => $p->sale_unit->value,
            'sale_unit_locked' => $ordered !== [] || DB::table('order_items')->where('product_id', $p->id)->exists(),
            'brand_id' => $p->brand_id,
            'primary_category_id' => $p->primary_category_id,
            'category_ids' => $p->categories->pluck('id')->push($p->primary_category_id)->unique()->sort()->values()->all(),
            'min_quantity' => $p->min_quantity->toNumber(),
            'max_quantity' => $p->max_quantity?->toNumber(),
            'quantity_step' => $p->quantity_step->toNumber(),
            'min_billable_area_m2' => $p->min_billable_area_m2?->toNumber(),
            'fixed_width_m' => $m($p->fixed_width_mm),
            'min_width_m' => $m($p->min_width_mm),
            'max_width_m' => $m($p->max_width_mm),
            'min_height_m' => $m($p->min_height_mm),
            'max_height_m' => $m($p->max_height_mm),
            'meta_title' => $p->meta_title,
            'meta_description' => $p->meta_description,
            'is_active' => $p->is_active,
            'is_featured' => $p->is_featured,
            'pickup_only' => $p->pickup_only,
            'activation_issues' => app(ProductActivation::class)->issues($p),
            'variants' => $variants->map(fn (ProductVariant $v) => self::variant($v, $inventory[$v->id] ?? null, $default, in_array($v->id, $ordered, true), $showCost))->values()->all(),
            'images' => $p->images->map(fn (ProductImage $i) => self::image($i, $p->name))->values()->all(),
            'created_at' => self::ts($p->created_at),
            'updated_at' => self::ts($p->updated_at),
            'deleted_at' => self::ts($p->deleted_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function variant(ProductVariant $v, ?object $inv, Quantity $defaultThreshold, bool $hasOrders, bool $showCost): array
    {
        $onHand = $inv !== null ? Quantity::fromString((string) $inv->on_hand) : Quantity::zero();
        $reserved = $inv !== null ? Quantity::fromString((string) $inv->reserved) : Quantity::zero();
        $threshold = $inv !== null && $inv->low_stock_threshold !== null ? Quantity::fromString((string) $inv->low_stock_threshold) : $defaultThreshold;
        $available = $onHand->subtract($reserved);
        $num = static fn ($v) => $v !== null ? (float) $v : null;

        return [
            'id' => $v->id,
            'sku' => $v->sku,
            'gtin' => $v->gtin,
            'name' => $v->name,
            'attributes' => (object) ($v->getAttribute('attributes') ?? []),
            'price_cents' => $v->price_cents,
            'promo_price_cents' => $v->promo_price_cents,
            'promo_starts_at' => self::ts($v->promo_starts_at),
            'promo_ends_at' => self::ts($v->promo_ends_at),
            'cost_cents' => $showCost ? $v->cost_cents : null,
            'weight_grams' => $v->weight_grams,
            'package_length_cm' => $num($v->package_length_cm),
            'package_width_cm' => $num($v->package_width_cm),
            'package_height_cm' => $num($v->package_height_cm),
            'roll_length_m' => $num($v->roll_length_m),
            'units_per_box' => $v->units_per_box,
            'units_per_package' => $v->units_per_package,
            'fixed_width_m' => $v->fixed_width_mm !== null ? Quantity::fromMilli($v->fixed_width_mm)->toNumber() : null,
            'is_active' => $v->is_active,
            'position' => $v->position,
            'has_orders' => $hasOrders,
            'inventory' => [
                'on_hand' => $onHand->toNumber(),
                'reserved' => $reserved->toNumber(),
                'available' => $available->toNumber(),
                'low_stock_threshold' => $threshold->toNumber(),
                'is_low_stock' => $available->lessThanOrEqual($threshold),
            ],
            'created_at' => self::ts($v->created_at),
            'updated_at' => self::ts($v->updated_at),
            'deleted_at' => self::ts($v->deleted_at),
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    public static function productListItems(Collection $products): array
    {
        $ids = $products->flatMap(fn (Product $p) => $p->variants->pluck('id'))->all();
        $inventory = $ids === [] ? collect() : DB::table('inventory')->whereIn('variant_id', $ids)->get()->keyBy('variant_id');
        $default = app(InventoryRecords::class)->defaultLowStockThreshold();

        return $products->map(function (Product $p) use ($inventory, $default) {
            $active = $p->variants->where('is_active', true);
            $total = Quantity::zero();
            $low = false;
            foreach ($active as $v) {
                $inv = $inventory[$v->id] ?? null;
                $available = $inv !== null ? Quantity::fromString((string) $inv->on_hand)->subtract(Quantity::fromString((string) $inv->reserved)) : Quantity::zero();
                $threshold = $inv !== null && $inv->low_stock_threshold !== null ? Quantity::fromString((string) $inv->low_stock_threshold) : $default;
                $total = $total->add($available);
                $low = $low || $available->lessThanOrEqual($threshold);
            }
            $img = $p->images->first();

            return [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'sale_unit' => $p->sale_unit->value,
                'primary_category' => $p->primaryCategory !== null ? ['id' => $p->primaryCategory->id, 'name' => $p->primaryCategory->name, 'slug' => $p->primaryCategory->slug, 'url_path' => '/'.$p->primaryCategory->slug] : null,
                'brand' => $p->brand !== null ? ['id' => $p->brand->id, 'name' => $p->brand->name, 'slug' => $p->brand->slug] : null,
                'image' => $img !== null ? self::image($img, $p->name) : null,
                'variants_count' => $p->variants->count(),
                'skus' => $p->variants->pluck('sku')->take(5)->values()->all(),
                'min_price_cents' => (int) ($active->min('price_cents') ?? $p->variants->min('price_cents') ?? 0),
                'total_available' => $total->toNumber(),
                'has_low_stock' => $low,
                'is_active' => $p->is_active,
                'is_featured' => $p->is_featured,
                'pickup_only' => $p->pickup_only,
                'updated_at' => self::ts($p->updated_at),
                'deleted_at' => self::ts($p->deleted_at),
            ];
        })->values()->all();
    }

    /**
     * Laravel paginator → API.md Paginated<T> with mapped data.
     *
     * @param  \Illuminate\Pagination\LengthAwarePaginator<int, mixed>  $paginator
     * @param  list<mixed>  $data
     * @return array<string, mixed>
     */
    public static function paginated($paginator, array $data): array
    {
        $b = $paginator->toArray();

        return [
            'data' => $data,
            'links' => ['first' => $b['first_page_url'], 'last' => $b['last_page_url'], 'prev' => $b['prev_page_url'], 'next' => $b['next_page_url']],
            'meta' => [
                'current_page' => $b['current_page'], 'from' => $b['from'], 'last_page' => $b['last_page'], 'path' => $b['path'],
                'per_page' => $b['per_page'], 'to' => $b['to'], 'total' => $b['total'], 'links' => $b['links'],
            ],
        ];
    }
}
