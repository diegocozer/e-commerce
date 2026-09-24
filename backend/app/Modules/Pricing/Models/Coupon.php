<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Pricing\Enums\CouponType;
use Database\Factories\Pricing\CouponFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Order-level coupon (DATABASE.md §3.2.6). `times_used` is changed only under
 * SELECT ... FOR UPDATE by the coupon service (not fillable).
 *
 * @property int $id
 * @property string $code
 * @property CouponType $type
 * @property int $value
 * @property int $min_order_cents
 * @property int|null $max_discount_cents
 * @property int|null $usage_limit
 * @property int|null $usage_limit_per_customer
 * @property int $times_used
 * @property bool $is_active
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'coupons';

    protected $fillable = [
        'code', 'description', 'type', 'value', 'min_order_cents', 'max_discount_cents', 'starts_at', 'ends_at',
        'usage_limit', 'usage_limit_per_customer', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'integer',
            'min_order_cents' => 'integer',
            'max_discount_cents' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'usage_limit' => 'integer',
            'usage_limit_per_customer' => 'integer',
            'times_used' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Codes are stored upper-case (DB-02). */
    protected function code(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => mb_strtoupper(trim($value)));
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    protected static function newFactory(): CouponFactory
    {
        return CouponFactory::new();
    }
}
