<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\CatalogQuery;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Support\CategoryTree;
use App\Modules\Catalog\Support\ImageUrls;
use App\Modules\Inventory\Contracts\InventoryRecords;
use App\Modules\Pricing\DTOs\PricingSubject;
use App\Shared\Domain\Money;
use App\Shared\Domain\PackageDimensions;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/** CatalogQuery backed by a constant number of queries per call (no N+1). */
final class EloquentCatalogQuery implements CatalogQuery
{
    public function variant(int $variantId): ?VariantData
    {
        return $this->variants([$variantId])[$variantId] ?? null;
    }

    public function variants(array $variantIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $variantIds)));
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.primary_category_id')
            ->whereIn('v.id', $ids)
            ->whereNull('v.deleted_at')
            ->select([
                'v.*', 'p.name as p_name', 'p.slug as p_slug', 'p.sale_unit', 'p.is_active as p_active', 'p.deleted_at as p_deleted',
                'p.pickup_only', 'p.brand_id', 'p.primary_category_id', 'p.min_quantity', 'p.max_quantity', 'p.quantity_step',
                'p.fixed_width_mm as p_fixed_width_mm', 'p.min_width_mm', 'p.max_width_mm', 'p.min_height_mm', 'p.max_height_mm',
                'p.min_billable_area_m2', 'c.slug as c_slug',
            ])
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $productIds = $rows->pluck('product_id')->unique()->all();
        $categories = [];
        foreach (DB::table('product_categories')->whereIn('product_id', $productIds)->get() as $pc) {
            $categories[(int) $pc->product_id][] = (int) $pc->category_id;
        }
        $images = [];
        foreach (DB::table('product_images')->whereIn('product_id', $productIds)->orderBy('position')->orderBy('id')->get() as $img) {
            $images[(int) $img->product_id] ??= $img;
            if ($img->variant_id !== null) {
                $images['v'.$img->variant_id] ??= $img;
            }
        }

        $inventory = DB::table('inventory')->whereIn('variant_id', $ids)->pluck('low_stock_threshold', 'variant_id')->all();
        $defaultThreshold = app(InventoryRecords::class)->defaultLowStockThreshold();
        $tree = app(CategoryTree::class);
        $out = [];
        foreach ($rows as $r) {
            $productId = (int) $r->product_id;
            $categoryIds = $categories[$productId] ?? [];
            $categoryIds[] = (int) $r->primary_category_id;
            $image = $images['v'.$r->id] ?? $images[$productId] ?? null;
            $saleUnit = SaleUnit::from($r->sale_unit);
            $fixedWidth = $r->fixed_width_mm !== null ? (int) $r->fixed_width_mm : ($r->p_fixed_width_mm !== null ? (int) $r->p_fixed_width_mm : null);
            $package = self::package($r, $saleUnit, $fixedWidth);
            $threshold = isset($inventory[(int) $r->id]) && $inventory[(int) $r->id] !== null
                ? Quantity::fromString((string) $inventory[(int) $r->id]) : $defaultThreshold;

            $out[(int) $r->id] = new VariantData(
                id: (int) $r->id,
                productId: $productId,
                productSlug: $r->p_slug,
                productName: $r->p_name,
                sku: $r->sku,
                name: $r->name,
                saleUnit: $saleUnit,
                isActive: (bool) $r->is_active,
                productIsActive: (bool) $r->p_active && $r->p_deleted === null,
                pickupOnly: (bool) $r->pickup_only,
                brandId: $r->brand_id !== null ? (int) $r->brand_id : null,
                primaryCategoryId: (int) $r->primary_category_id,
                categoryIdsWithAncestors: $tree->withAncestors(array_values(array_unique($categoryIds))),
                minQuantity: Quantity::fromString((string) $r->min_quantity),
                maxQuantity: $r->max_quantity !== null ? Quantity::fromString((string) $r->max_quantity) : null,
                quantityStep: Quantity::fromString((string) $r->quantity_step),
                fixedWidthMm: $fixedWidth,
                minWidthMm: $r->min_width_mm !== null ? (int) $r->min_width_mm : null,
                maxWidthMm: $r->max_width_mm !== null ? (int) $r->max_width_mm : null,
                minHeightMm: $r->min_height_mm !== null ? (int) $r->min_height_mm : null,
                maxHeightMm: $r->max_height_mm !== null ? (int) $r->max_height_mm : null,
                minBillableArea: $r->min_billable_area_m2 !== null ? Quantity::fromString((string) $r->min_billable_area_m2) : null,
                weightGrams: (int) $r->weight_grams,
                package: $package,
                unitsPerPackage: $r->units_per_package !== null ? (int) $r->units_per_package : null,
                unitsPerBox: $r->units_per_box !== null ? (int) $r->units_per_box : null,
                rollLengthM: $r->roll_length_m !== null ? (string) $r->roll_length_m : null,
                imageUrl: $image !== null ? (ImageUrls::for($image->disk, $image->path, $image->width_px)['w300'] ?? null) : null,
                primaryCategorySlug: (string) ($r->c_slug ?? ''),
                attributes: array_map('strval', (array) (json_decode((string) $r->attributes, true) ?: [])),
                image: $image !== null ? [
                    'id' => (int) $image->id,
                    'urls' => ImageUrls::for($image->disk, $image->path, $image->width_px !== null ? (int) $image->width_px : null),
                    'alt' => $image->alt !== null && $image->alt !== '' ? $image->alt : $r->p_name,
                    'width' => $image->width_px !== null ? (int) $image->width_px : null,
                    'height' => $image->height_px !== null ? (int) $image->height_px : null,
                    'position' => (int) $image->position,
                    'variant_id' => $image->variant_id !== null ? (int) $image->variant_id : null,
                ] : null,
                lowStockThreshold: $threshold,
            );
        }

        return $out;
    }

    /**
     * Logistic package (SHIPPING.md §2.2/§2.3). Rolled goods (LINEAR_METER/SQUARE_METER)
     * only register the roll diameter (package_width/height_cm); the length is the
     * material width (fixed width, else package_length_cm, else the diameter).
     */
    private static function package(object $r, SaleUnit $unit, ?int $fixedWidthMm): ?PackageDimensions
    {
        $rolled = in_array($unit, [SaleUnit::LinearMeter, SaleUnit::SquareMeter], true);
        if (! $rolled) {
            return ($r->package_length_cm !== null && $r->package_width_cm !== null && $r->package_height_cm !== null)
                ? PackageDimensions::fromCentimeters((string) $r->package_length_cm, (string) $r->package_width_cm, (string) $r->package_height_cm)
                : null;
        }
        if ($r->package_width_cm === null && $r->package_height_cm === null) {
            return null;
        }
        $w = PackageDimensions::fromCentimeters('1', (string) ($r->package_width_cm ?? $r->package_height_cm), (string) ($r->package_height_cm ?? $r->package_width_cm));
        $diameter = max($w->widthMm, $w->heightMm);
        $length = $fixedWidthMm
            ?? ($r->package_length_cm !== null ? PackageDimensions::fromCentimeters((string) $r->package_length_cm, '1', '1')->lengthMm : $diameter);

        return new PackageDimensions($length, $diameter, $diameter);
    }

    public function variantBySlug(string $productSlug, int $variantId): ?VariantData
    {
        $variant = $this->variant($variantId);

        return $variant !== null && $variant->productSlug === $productSlug ? $variant : null;
    }

    public function pricingSubject(int $variantId): PricingSubject
    {
        return $this->pricingSubjects([$variantId])[$variantId]
            ?? throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$variantId]);
    }

    public function pricingSubjects(array $variantIds): array
    {
        $out = [];
        $variants = $this->variants($variantIds);
        $promo = $this->promoFields(array_keys($variants));
        foreach ($variants as $id => $v) {
            $out[$id] = self::subjectFrom($v, ...$promo[$id]);
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{0: Money, 1: ?Money, 2: ?CarbonImmutable, 3: ?CarbonImmutable}>
     */
    private function promoFields(array $ids): array
    {
        $out = [];
        foreach (DB::table('product_variants')->whereIn('id', $ids)->get(['id', 'price_cents', 'promo_price_cents', 'promo_starts_at', 'promo_ends_at']) as $r) {
            $out[(int) $r->id] = [
                Money::ofCents((int) $r->price_cents),
                $r->promo_price_cents !== null ? Money::ofCents((int) $r->promo_price_cents) : null,
                $r->promo_starts_at !== null ? CarbonImmutable::parse($r->promo_starts_at) : null,
                $r->promo_ends_at !== null ? CarbonImmutable::parse($r->promo_ends_at) : null,
            ];
        }

        return $out;
    }

    private static function subjectFrom(VariantData $v, Money $base, ?Money $promo, ?CarbonImmutable $start, ?CarbonImmutable $end): PricingSubject
    {
        return new PricingSubject($v->id, $v->productId, $v->brandId, $v->categoryIdsWithAncestors, $base, $promo, $start, $end);
    }

    /** Builds a PricingSubject from a loaded variant model (storefront listing helper). */
    public static function subjectForModel(ProductVariant $variant, ?int $brandId, array $categoryIdsWithAncestors): PricingSubject
    {
        return new PricingSubject(
            $variant->id,
            $variant->product_id,
            $brandId,
            $categoryIdsWithAncestors,
            Money::ofCents($variant->price_cents),
            $variant->promo_price_cents !== null ? Money::ofCents($variant->promo_price_cents) : null,
            $variant->promo_starts_at,
            $variant->promo_ends_at,
        );
    }
}
