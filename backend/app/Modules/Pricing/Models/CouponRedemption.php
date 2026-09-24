<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Customers\Models\Customer;
use Database\Factories\Pricing\CouponRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Coupon usage created with the order (DATABASE.md §3.2.7). Only
 * `cancelled_at` may change afterwards. `order_id` references Orders, which
 * Pricing does not depend on (Order::couponRedemption() is the read side).
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $customer_id
 * @property int $order_id
 * @property int $discount_cents
 */
class CouponRedemption extends Model
{
    /** @use HasFactory<CouponRedemptionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'coupon_redemptions';

    protected $fillable = ['coupon_id', 'customer_id', 'order_id', 'discount_cents'];

    protected function casts(): array
    {
        return [
            'discount_cents' => 'integer',
            'created_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected static function newFactory(): CouponRedemptionFactory
    {
        return CouponRedemptionFactory::new();
    }
}
