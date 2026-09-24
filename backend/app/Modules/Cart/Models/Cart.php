<?php

declare(strict_types=1);

namespace App\Modules\Cart\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Pricing\Models\Coupon;
use App\Modules\Shipping\Models\ShippingQuote;
use Database\Factories\Cart\CartFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Guest or customer cart (DATABASE.md §3.5.1). `token` (uuid) is the
 * X-Cart-Token value. Ownership/conversion fields are set explicitly by Cart
 * actions (customer_id, converted_*). `converted_order_id` references Orders,
 * which Cart does not depend on.
 *
 * @property int $id
 * @property string $token
 * @property int|null $customer_id
 * @property string|null $postal_code
 * @property int|null $coupon_id
 */
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'carts';

    protected $fillable = ['postal_code', 'expires_at'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['token'];
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    /** @return HasMany<ShippingQuote, $this> */
    public function shippingQuotes(): HasMany
    {
        return $this->hasMany(ShippingQuote::class);
    }

    protected static function newFactory(): CartFactory
    {
        return CartFactory::new();
    }
}
