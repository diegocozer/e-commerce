<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Pricing\Models\Promotion;
use App\Shared\Casts\QuantityCast;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Database\Factories\Catalog\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Storefront product (DATABASE.md §3.1.3). Sale unit and quantity/dimension
 * rules live here (ADR-004); price, stock, SKU and weight live in variants.
 * `search_vector` is maintained by the search indexer (never mass assigned).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property SaleUnit $sale_unit
 * @property int|null $brand_id
 * @property int $primary_category_id
 * @property Quantity $min_quantity
 * @property Quantity|null $max_quantity
 * @property Quantity $quantity_step
 * @property Quantity|null $min_billable_area_m2
 * @property int|null $fixed_width_mm
 * @property int|null $min_width_mm
 * @property int|null $max_width_mm
 * @property int|null $min_height_mm
 * @property int|null $max_height_mm
 * @property bool $is_active
 * @property bool $is_featured
 * @property bool $pickup_only
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'name', 'slug', 'short_description', 'description', 'sale_unit', 'brand_id', 'primary_category_id',
        'min_quantity', 'max_quantity', 'quantity_step', 'min_billable_area_m2', 'fixed_width_mm',
        'min_width_mm', 'max_width_mm', 'min_height_mm', 'max_height_mm', 'meta_title', 'meta_description',
        'is_active', 'is_featured', 'pickup_only',
    ];

    protected $hidden = ['search_vector'];

    protected function casts(): array
    {
        return [
            'sale_unit' => SaleUnit::class,
            'min_quantity' => QuantityCast::class,
            'max_quantity' => QuantityCast::class,
            'quantity_step' => QuantityCast::class,
            'min_billable_area_m2' => QuantityCast::class,
            'fixed_width_mm' => 'integer',
            'min_width_mm' => 'integer',
            'max_width_mm' => 'integer',
            'min_height_mm' => 'integer',
            'max_height_mm' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'pickup_only' => 'boolean',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories')->withPivot('position');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsToMany<Promotion, $this> */
    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class, 'promotion_products');
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
