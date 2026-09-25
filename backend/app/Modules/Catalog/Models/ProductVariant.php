<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\Casts\JsonObjectCast;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Pricing\Models\CustomerPrice;
use App\Modules\Pricing\Models\PriceTier;
use Database\Factories\Catalog\ProductVariantFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sellable unit (DATABASE.md §3.1.5): cart, price, stock and orders reference
 * the variant. Money in cents (int). `cost_cents` must never be exposed in
 * the storefront.
 *
 * @property int $id
 * @property int $product_id
 * @property string $sku
 * @property string $name
 * @property array<string, string> $attributes
 * @property int $price_cents
 * @property int|null $promo_price_cents
 * @property int|null $cost_cents
 * @property int $weight_grams
 * @property int|null $fixed_width_mm
 * @property bool $is_active
 * @property int $position
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'product_variants';

    protected $fillable = [
        'product_id', 'sku', 'gtin', 'name', 'attributes', 'price_cents', 'promo_price_cents', 'promo_starts_at',
        'promo_ends_at', 'cost_cents', 'weight_grams', 'package_length_cm', 'package_width_cm', 'package_height_cm',
        'roll_length_m', 'units_per_box', 'units_per_package', 'fixed_width_mm', 'is_active', 'position',
    ];

    protected $hidden = ['cost_cents'];

    protected function casts(): array
    {
        return [
            'attributes' => JsonObjectCast::class,
            'price_cents' => 'integer',
            'promo_price_cents' => 'integer',
            'promo_starts_at' => 'immutable_datetime',
            'promo_ends_at' => 'immutable_datetime',
            'cost_cents' => 'integer',
            'weight_grams' => 'integer',
            'package_length_cm' => 'decimal:1',
            'package_width_cm' => 'decimal:1',
            'package_height_cm' => 'decimal:1',
            'roll_length_m' => 'decimal:3',
            'units_per_box' => 'integer',
            'units_per_package' => 'integer',
            'fixed_width_mm' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** SKUs are stored upper-case (DB-02). */
    protected function sku(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => mb_strtoupper(trim($value)));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasOne<Inventory, $this> */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class, 'variant_id');
    }

    /** @return HasMany<PriceTier, $this> */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(PriceTier::class, 'variant_id')->orderBy('min_quantity');
    }

    /** @return HasMany<CustomerPrice, $this> */
    public function customerPrices(): HasMany
    {
        return $this->hasMany(CustomerPrice::class, 'variant_id');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'variant_id')->orderBy('position');
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}
