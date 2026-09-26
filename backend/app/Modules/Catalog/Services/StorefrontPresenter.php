<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Support\CategoryTree;
use App\Modules\Catalog\Support\ImageUrls;
use App\Modules\Inventory\Contracts\InventoryRecords;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Pricing\Contracts\PriceTierTable;
use App\Modules\Pricing\DTOs\PriceContext;
use App\Modules\Pricing\DTOs\PriceQuote;
use App\Modules\Pricing\DTOs\TierPrice;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the storefront JSON shapes of API.md §2.2/§2.3 (ProductCard,
 * ProductDetail, CategoryNode/Detail, Seo) with prices resolved in batch for
 * the current customer and availability per A-09.
 */
final class StorefrontPresenter
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly PriceTierTable $tierTable,
        private readonly InventoryRecords $inventory,
        private readonly SettingsRepository $settings,
    ) {}

    // ---------------------------------------------------------------- cards

    /**
     * @param  Collection<int, Product>  $products  with variants (active), images, brand, primaryCategory loaded
     * @return list<array<string, mixed>>
     */
    public function cards(Collection $products, ?int $customerId): array
    {
        $variants = $products->flatMap(fn (Product $p) => $p->variants)->values();
        $quotes = $this->quotesAtMinimum($products, $customerId);
        $availability = $this->availability($variants);
        $tree = app(CategoryTree::class);
        $minTier = $this->minBaseTierPrices($variants->pluck('id')->all());

        $out = [];
        foreach ($products as $product) {
            $active = $product->variants;
            $default = $active->first();
            if ($default === null) {
                continue;
            }
            $quote = $quotes[$default->id];
            $from = null;
            foreach ($active as $v) {
                $candidate = min($quotes[$v->id]->unitPrice->cents(), $minTier[$v->id] ?? PHP_INT_MAX);
                $from = $from === null ? $candidate : min($from, $candidate);
            }
            $statuses = $active->map(fn (ProductVariant $v) => $availability[$v->id]['status'])->all();
            $status = in_array('in_stock', $statuses, true) ? 'in_stock' : (in_array('low_stock', $statuses, true) ? 'low_stock' : 'out_of_stock');

            $out[] = [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'url_path' => $this->productPath($product),
                'sale_unit' => $product->sale_unit->value,
                'sale_unit_label' => $product->sale_unit->label(),
                'sale_unit_abbr' => $product->sale_unit->abbreviation(),
                'brand' => $this->brandRef($product->brand),
                'primary_category' => $this->categoryRef($product->primaryCategory),
                'image' => $this->image($product->images->first(), $product->name),
                'variants_count' => $active->count(),
                'default_variant' => ['id' => $default->id, 'sku' => $default->sku, 'name' => $default->name],
                'key_attribute' => $this->keyAttribute($product, $default),
                'price' => [
                    'unit_price_cents' => $quote->unitPrice->cents(),
                    'compare_at_cents' => $quote->compareAt()?->cents(),
                    'price_source' => $quote->source->value,
                    'price_source_label' => $quote->sourceLabel,
                    'from_price_cents' => $from !== null && $from < $quote->unitPrice->cents() ? $from : null,
                ],
                'availability' => ['status' => $status],
                'pickup_only' => $product->pickup_only,
                'is_featured' => $product->is_featured,
                'quick_add' => in_array($product->sale_unit, [SaleUnit::Unit, SaleUnit::Roll, SaleUnit::Box], true)
                    && $active->count() === 1 && $status !== 'out_of_stock' && $product->min_quantity->milli() === 1000,
            ];
        }
        unset($tree);

        return $out;
    }

    // --------------------------------------------------------------- detail

    /** @return array<string, mixed> ProductDetail */
    public function detail(Product $product, ?int $customerId): array
    {
        $variants = $product->variants;
        $quotes = $this->quotesAtMinimum(collect([$product]), $customerId);
        $availability = $this->availability($variants);
        $now = CarbonImmutable::now();

        $variantRows = [];
        foreach ($variants as $i => $v) {
            $quote = $quotes[$v->id];
            $minimum = $this->minimumBillable($product);
            $ctx = new PriceContext(EloquentCatalogQuery::subjectForModel($v, $product->brand_id, $this->categoryIdsWithAncestors($product)), $minimum, null, $customerId, $now);
            $variantRows[] = [
                'id' => $v->id,
                'sku' => $v->sku,
                'gtin' => $v->gtin,
                'name' => $v->name,
                'attributes' => (object) ($v->getAttribute('attributes') ?? []),
                'position' => $v->position,
                'is_default' => $i === 0,
                'image_ids' => $product->images->where('variant_id', $v->id)->pluck('id')->values()->all(),
                'rules' => $this->rules($product, $v),
                'price' => $this->variantPrice($quote, $this->tierDisplay($this->tierTable->tiersFor($ctx, $minimum), $product)),
                'availability' => $availability[$v->id],
                'weight_grams' => $v->weight_grams,
                'roll_length_m' => $v->roll_length_m !== null ? Quantity::fromString((string) $v->roll_length_m)->toNumber() : null,
                'units_per_box' => $v->units_per_box,
            ];
        }

        $breadcrumbs = $this->productBreadcrumbs($product);

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'url_path' => $this->productPath($product),
            'short_description' => $product->short_description,
            'description_html' => $product->description,
            'specifications' => array_values($product->specifications ?? []),
            'sale_unit' => $product->sale_unit->value,
            'sale_unit_label' => $product->sale_unit->label(),
            'sale_unit_abbr' => $product->sale_unit->abbreviation(),
            'brand' => $this->brandRef($product->brand),
            'primary_category' => $this->categoryRef($product->primaryCategory),
            'categories' => $product->categories->filter(fn (Category $c) => $c->is_active)->map(fn (Category $c) => $this->categoryRef($c))->values()->all(),
            'breadcrumbs' => $breadcrumbs,
            'images' => $product->images->map(fn (ProductImage $img, int $i) => $this->image($img, $product->name, $i + 1, $product->images->count()))->values()->all(),
            'attribute_axes' => $this->attributeAxes($variants),
            'variants' => $variantRows,
            'default_variant_id' => $variants->first()?->id,
            'pickup_only' => $product->pickup_only,
            'is_featured' => $product->is_featured,
            'seo' => $this->productSeo($product, $breadcrumbs, $variants, $availability),
        ];
    }

    /**
     * Visitor prices at the minimum quantity (JSON-LD, RN-BUS-006) + SEO block.
     *
     * @param  list<array{name: string, url_path: ?string}>  $breadcrumbs
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array<int, array{status: string, available_quantity: int|float|null}>  $availability
     * @return array<string, mixed>
     */
    public function productSeo(Product $product, array $breadcrumbs, Collection $variants, array $availability): array
    {
        $visitorQuotes = $this->quotesAtMinimum(collect([$product]), null);
        $path = $this->productPath($product);
        $image = $product->images->first();
        $imageUrl = $image !== null ? (ImageUrls::for($image->disk, $image->path, $image->width_px)['w1600'] ?? null) : null;
        $unitCode = match ($product->sale_unit) {
            SaleUnit::LinearMeter => 'MTR', SaleUnit::SquareMeter => 'MTK', SaleUnit::Kg => 'KGM', default => 'C62',
        };

        $offers = [];
        foreach ($variants as $v) {
            $price = number_format($visitorQuotes[$v->id]->unitPrice->cents() / 100, 2, '.', '');
            $offers[] = [
                '@type' => 'Offer', 'sku' => $v->sku, 'price' => $price, 'priceCurrency' => 'BRL',
                'availability' => ($availability[$v->id]['status'] ?? 'in_stock') === 'out_of_stock' ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
                'url' => $this->absolute($path).'?sku='.rawurlencode($v->sku),
                'priceSpecification' => ['@type' => 'UnitPriceSpecification', 'price' => $price, 'priceCurrency' => 'BRL', 'unitCode' => $unitCode],
            ];
        }

        $productLd = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'sku' => $variants->first()?->sku,
            'description' => $product->meta_description ?? $product->short_description,
            'brand' => $product->brand !== null ? ['@type' => 'Brand', 'name' => $product->brand->name] : null,
            'image' => $imageUrl !== null ? [$imageUrl] : null,
            'offers' => $offers,
        ], static fn ($v) => $v !== null);

        return [
            'title' => $product->meta_title ?? ($product->name.' | '.$this->storeName()),
            'description' => $product->meta_description ?? $this->truncate($product->short_description ?? strip_tags((string) $product->description) ?: $product->name, 160),
            'canonical_path' => $path,
            'canonical_url' => $this->absolute($path),
            'robots' => 'index,follow',
            'og_image_url' => $imageUrl,
            'json_ld' => [$productLd, $this->breadcrumbLd($breadcrumbs)],
        ];
    }

    // ------------------------------------------------------------ categories

    /** @return list<array<string, mixed>> active tree (max 3 levels) */
    public function categoryTree(): array
    {
        $categories = Category::query()->where('is_active', true)->orderBy('position')->orderBy('id')->get();
        $byParent = $categories->groupBy(fn (Category $c) => $c->parent_id ?? 0);
        $build = function (int $parentId, int $depth) use (&$build, $byParent): array {
            if ($depth > 3) {
                return [];
            }

            return ($byParent[$parentId] ?? collect())->map(fn (Category $c) => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'url_path' => '/'.$c->slug,
                'image_url' => ImageUrls::url($this->disk(), $c->image_path), 'position' => $c->position,
                'children' => $build($c->id, $depth + 1),
            ])->values()->all();
        };

        return $build(0, 1);
    }

    /** @return array<string, mixed> CategoryDetail */
    public function categoryDetail(Category $category): array
    {
        $children = Category::query()->where('parent_id', $category->id)->where('is_active', true)->orderBy('position')->get();
        $grandChildren = Category::query()->whereIn('parent_id', $children->pluck('id'))->where('is_active', true)->orderBy('position')->get()->groupBy('parent_id');
        $node = fn (Category $c, array $kids) => [
            'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'url_path' => '/'.$c->slug,
            'image_url' => ImageUrls::url($this->disk(), $c->image_path), 'position' => $c->position, 'children' => $kids,
        ];
        $breadcrumbs = $this->categoryBreadcrumbs($category);
        $path = '/'.$category->slug;

        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'url_path' => $path,
            'description_html' => $category->description,
            'image_url' => ImageUrls::url($this->disk(), $category->image_path),
            'breadcrumbs' => $breadcrumbs,
            'children' => $children->map(fn (Category $c) => $node($c, ($grandChildren[$c->id] ?? collect())->map(fn (Category $g) => $node($g, []))->values()->all()))->values()->all(),
            'seo' => [
                'title' => $category->meta_title ?? ($category->name.' | '.$this->storeName()),
                'description' => $category->meta_description ?? $this->truncate(strip_tags((string) $category->description) ?: $category->name, 160),
                'canonical_path' => $path,
                'canonical_url' => $this->absolute($path),
                'robots' => 'index,follow',
                'og_image_url' => ImageUrls::url($this->disk(), $category->image_path),
                'json_ld' => [$this->breadcrumbLd($breadcrumbs)],
            ],
        ];
    }

    /** @return list<array{name: string, url_path: ?string}> */
    public function categoryBreadcrumbs(Category $category): array
    {
        $tree = app(CategoryTree::class);
        $crumbs = [['name' => 'Início', 'url_path' => '/']];
        foreach ($tree->ancestorIds($category->id) as $id) {
            $node = $tree->node($id);
            if ($node !== null) {
                $crumbs[] = ['name' => $node->name, 'url_path' => '/'.$node->slug];
            }
        }
        $crumbs[] = ['name' => $category->name, 'url_path' => null];

        return $crumbs;
    }

    /** @return list<array{name: string, url_path: ?string}> */
    public function productBreadcrumbs(Product $product): array
    {
        $tree = app(CategoryTree::class);
        $crumbs = [['name' => 'Início', 'url_path' => '/']];
        foreach ([...$tree->ancestorIds($product->primary_category_id), $product->primary_category_id] as $id) {
            $node = $tree->node($id);
            if ($node !== null) {
                $crumbs[] = ['name' => $node->name, 'url_path' => '/'.$node->slug];
            }
        }
        $crumbs[] = ['name' => $product->name, 'url_path' => null];

        return $crumbs;
    }

    /** @param  list<array{name: string, url_path: ?string}>  $breadcrumbs */
    public function breadcrumbLd(array $breadcrumbs): array
    {
        $items = [];
        foreach ($breadcrumbs as $i => $crumb) {
            $items[] = array_filter([
                '@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'],
                'item' => $crumb['url_path'] !== null ? $this->absolute($crumb['url_path']) : null,
            ], static fn ($v) => $v !== null);
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    // --------------------------------------------------------------- pieces

    /** @return array<string, mixed> SaleUnitRules */
    public function rules(Product $product, ProductVariant $variant): array
    {
        $fixed = $variant->fixed_width_mm ?? $product->fixed_width_mm;
        $m = static fn (?int $mm) => $mm !== null ? Quantity::fromMilli($mm)->toNumber() : null;

        return [
            'sale_unit' => $product->sale_unit->value,
            'input' => $product->sale_unit->usesDimensions() ? 'dimensions' : ($product->sale_unit->allowsFraction() ? 'decimal' : 'integer'),
            'min_quantity' => $product->min_quantity->toNumber(),
            'max_quantity' => $product->max_quantity?->toNumber(),
            'quantity_step' => $product->quantity_step->toNumber(),
            'fixed_width_m' => $m($fixed),
            'min_width_m' => $m($product->min_width_mm),
            'max_width_m' => $m($product->max_width_mm),
            'min_height_m' => $m($product->min_height_mm),
            'max_height_m' => $m($product->max_height_mm),
            'min_billable_area_m2' => $product->min_billable_area_m2?->toNumber(),
            'dimension_decimals' => 2,
            'max_pieces' => 1000,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tiers
     * @return array<string, mixed> VariantPrice
     */
    public function variantPrice(PriceQuote $quote, array $tiers): array
    {
        return [
            'unit_price_cents' => $quote->unitPrice->cents(),
            'base_unit_price_cents' => $quote->baseUnitPrice->cents(),
            'compare_at_cents' => $quote->compareAt()?->cents(),
            'price_source' => $quote->source->value,
            'price_source_label' => $quote->sourceLabel,
            'promotion' => $quote->promotionName !== null
                ? ['name' => $quote->promotionName, 'ends_at' => $quote->promotionEndsAt?->utc()->format('Y-m-d\TH:i:s\Z')]
                : null,
            'tiers' => $tiers,
        ];
    }

    /**
     * PriceTierDisplay rows: max = next min − smallest step (0.001 for m², else quantity_step).
     *
     * @param  list<TierPrice>  $tiers
     * @return list<array<string, mixed>>
     */
    public function tierDisplay(array $tiers, Product $product): array
    {
        $step = $product->sale_unit->usesDimensions() ? Quantity::fromMilli(1) : $product->quantity_step;
        $rows = [];
        foreach ($tiers as $i => $tier) {
            $next = $tiers[$i + 1] ?? null;
            $rows[] = [
                'min_quantity' => $tier->minQuantity->toNumber(),
                'max_quantity' => $next !== null ? $next->minQuantity->subtract($step)->toNumber() : null,
                'unit_price_cents' => $tier->unitPrice->cents(),
                'price_source' => $tier->source->value,
            ];
        }

        return $rows;
    }

    /** Minimum billable quantity used for display prices (m²: min area or 0.001). */
    public function minimumBillable(Product $product): Quantity
    {
        if ($product->sale_unit->usesDimensions()) {
            return Quantity::fromMilli(1);
        }

        return $product->min_quantity;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, PriceQuote> variant id => quote at minimum
     */
    public function quotesAtMinimum(Collection $products, ?int $customerId): array
    {
        $contexts = [];
        $ids = [];
        $now = CarbonImmutable::now();
        foreach ($products as $product) {
            $categories = $this->categoryIdsWithAncestors($product);
            $qty = $product->sale_unit->usesDimensions() ? ($product->min_billable_area_m2 ?? Quantity::fromMilli(1000)) : $product->min_quantity;
            foreach ($product->variants as $v) {
                $contexts[] = new PriceContext(EloquentCatalogQuery::subjectForModel($v, $product->brand_id, $categories), $qty, null, $customerId, $now);
                $ids[] = $v->id;
            }
        }
        $quotes = $this->prices->resolveMany($contexts);

        return array_combine($ids, $quotes) ?: [];
    }

    /**
     * RN-CAT-017 / A-09.
     *
     * @param  Collection<int, ProductVariant>  $variants  with product loaded
     * @return array<int, array{status: string, available_quantity: int|float|null}>
     */
    public function availability(Collection $variants): array
    {
        $ids = $variants->pluck('id')->all();
        $rows = $ids === [] ? collect() : DB::table('inventory')->whereIn('variant_id', $ids)->get()->keyBy('variant_id');
        $default = $this->inventory->defaultLowStockThreshold();
        $out = [];
        foreach ($variants as $v) {
            $row = $rows[$v->id] ?? null;
            $available = $row !== null
                ? Quantity::fromString((string) $row->on_hand)->subtract(Quantity::fromString((string) $row->reserved))
                : Quantity::zero();
            $threshold = $row !== null && $row->low_stock_threshold !== null ? Quantity::fromString((string) $row->low_stock_threshold) : $default;
            $min = $v->product->sale_unit->usesDimensions() ? Quantity::fromMilli(1) : $v->product->min_quantity;

            if ($available->lessThan($min) || ! $available->isPositive()) {
                $out[$v->id] = ['status' => 'out_of_stock', 'available_quantity' => null];
            } elseif ($available->lessThanOrEqual($threshold)) {
                $out[$v->id] = ['status' => 'low_stock', 'available_quantity' => $available->toNumber()];
            } else {
                $out[$v->id] = ['status' => 'in_stock', 'available_quantity' => null];
            }
        }

        return $out;
    }

    /** @return list<int> */
    public function categoryIdsWithAncestors(Product $product): array
    {
        $ids = $product->relationLoaded('categories') ? $product->categories->pluck('id')->all()
            : DB::table('product_categories')->where('product_id', $product->id)->pluck('category_id')->map(fn ($i) => (int) $i)->all();
        $ids[] = $product->primary_category_id;

        return app(CategoryTree::class)->withAncestors(array_values(array_unique($ids)));
    }

    /** @return array<string, mixed>|null */
    public function image(?ProductImage $image, string $productName, int $index = 1, int $count = 1): ?array
    {
        if ($image === null) {
            return null;
        }

        return [
            'id' => $image->id,
            'urls' => ImageUrls::for($image->disk, $image->path, $image->width_px),
            'alt' => $image->alt !== null && $image->alt !== '' ? $image->alt : ($count > 1 ? "{$productName} — foto {$index} de {$count}" : $productName),
            'width' => $image->width_px,
            'height' => $image->height_px,
            'position' => $image->position,
            'variant_id' => $image->variant_id,
        ];
    }

    public function productPath(Product $product): string
    {
        return '/'.($product->primaryCategory?->slug ?? '').'/'.$product->slug;
    }

    /** @return array{id: int, name: string, slug: string, url_path: string}|null */
    public function categoryRef(?Category $c): ?array
    {
        return $c === null ? null : ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'url_path' => '/'.$c->slug];
    }

    /** @return array{id: int, name: string, slug: string}|null */
    public function brandRef(?Brand $b): ?array
    {
        return $b === null ? null : ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug];
    }

    public function absolute(string $path): string
    {
        $base = rtrim((string) (config('catalog.storefront_url') ?? config('app.url')), '/');

        return $base.$path;
    }

    public function disk(): string
    {
        return (string) config('catalog.images_disk', 'public');
    }

    private function keyAttribute(Product $product, ProductVariant $variant): ?string
    {
        $fixed = $variant->fixed_width_mm ?? $product->fixed_width_mm;

        return match (true) {
            $fixed !== null && in_array($product->sale_unit, [SaleUnit::LinearMeter, SaleUnit::SquareMeter], true) => 'Largura '.Quantity::fromMilli($fixed)->format(2).' m',
            $product->sale_unit === SaleUnit::Roll && $variant->roll_length_m !== null => 'Rolo '.Quantity::fromString((string) $variant->roll_length_m)->format(0).' m',
            $product->sale_unit === SaleUnit::Box && $variant->units_per_box !== null => 'Caixa c/ '.$variant->units_per_box.' un',
            default => null,
        };
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     * @return list<array{key: string, label: string, values: list<string>}>
     */
    private function attributeAxes(Collection $variants): array
    {
        $axes = [];
        foreach ($variants as $v) {
            foreach ((array) ($v->getAttribute('attributes') ?? []) as $key => $value) {
                $axes[$key] ??= [];
                if (! in_array((string) $value, $axes[$key], true)) {
                    $axes[$key][] = (string) $value;
                }
            }
        }
        $out = [];
        foreach ($axes as $key => $values) {
            if (count($values) > 1 || $variants->count() === 1) {
                $out[] = ['key' => (string) $key, 'label' => mb_convert_case(str_replace('_', ' ', (string) $key), MB_CASE_TITLE), 'values' => $values];
            }
        }

        return $variants->count() > 1 ? $out : [];
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, int> lowest base tier price per variant
     */
    private function minBaseTierPrices(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return DB::table('price_tiers')->whereIn('variant_id', $variantIds)->whereNull('price_list_id')
            ->groupBy('variant_id')->selectRaw('variant_id, min(price_cents) as p')->pluck('p', 'variant_id')
            ->mapWithKeys(fn ($p, $id) => [(int) $id => (int) $p])->all();
    }

    private function storeName(): string
    {
        return (string) ($this->settings->get(SettingKey::StoreName) ?? 'CV Suprimentos');
    }

    private function truncate(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }
}
