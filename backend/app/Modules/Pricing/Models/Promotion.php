<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Pricing\Enums\PromotionDiscountType;
use App\Modules\Pricing\Enums\PromotionScope;
use Database\Factories\Pricing\PromotionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Automatic promotion (DATABASE.md §3.2.4). Targets live in three pivots
 * (promotion_products / promotion_categories / promotion_brands, DB-08).
 * Pricing does not depend on Catalog, so target relations are declared on the
 * Catalog side (Product/Category/Brand::promotions()); here targets are
 * handled by id through the helpers below.
 *
 * @property int $id
 * @property string $name
 * @property PromotionDiscountType $discount_type
 * @property int $value
 * @property PromotionScope $scope
 * @property bool $is_active
 * @property int $priority
 */
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    use SoftDeletes;

    public const array TARGET_PIVOTS = [
        'product' => ['promotion_products', 'product_id'],
        'category' => ['promotion_categories', 'category_id'],
        'brand' => ['promotion_brands', 'brand_id'],
    ];

    protected $table = 'promotions';

    protected $fillable = ['name', 'description', 'discount_type', 'value', 'scope', 'starts_at', 'ends_at', 'is_active', 'priority'];

    protected function casts(): array
    {
        return [
            'discount_type' => PromotionDiscountType::class,
            'value' => 'integer',
            'scope' => PromotionScope::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    /**
     * Active and within the validity window at the given instant.
     *
     * @param  Builder<Promotion>  $query
     */
    public function scopeCurrent(Builder $query, ?\DateTimeInterface $at = null): void
    {
        $at ??= now();
        $query->where('is_active', true)
            ->where('starts_at', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    /**
     * Replaces the targets of one kind ("product", "category" or "brand").
     *
     * @param  list<int>  $ids
     */
    public function syncTargets(string $kind, array $ids): void
    {
        [$table, $column] = self::TARGET_PIVOTS[$kind];
        DB::table($table)->where('promotion_id', $this->id)->delete();
        DB::table($table)->insert(array_map(fn (int $id): array => ['promotion_id' => $this->id, $column => $id], array_values(array_unique($ids))));
    }

    /** @return list<int> */
    public function targetIds(string $kind): array
    {
        [$table, $column] = self::TARGET_PIVOTS[$kind];

        return DB::table($table)->where('promotion_id', $this->id)->orderBy($column)->pluck($column)->map(fn ($id): int => (int) $id)->all();
    }

    protected static function newFactory(): PromotionFactory
    {
        return PromotionFactory::new();
    }
}
