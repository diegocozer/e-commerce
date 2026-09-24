<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Enums\WeightBasis;
use Database\Factories\Shipping\ShippingMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shipping method (pickup / own_delivery / table_rate / carrier — DATABASE.md §3.8.2).
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property ShippingMethodType $type
 * @property int|null $carrier_id
 * @property int $delivery_days_min
 * @property int $delivery_days_max
 * @property bool $is_active
 * @property int $position
 * @property WeightBasis $weight_basis
 * @property int|null $cubic_divisor
 * @property int $handling_days
 * @property bool $accepts_free_shipping_coupon
 */
class ShippingMethod extends Model
{
    /** @use HasFactory<ShippingMethodFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'shipping_methods';

    protected $fillable = [
        'name', 'code', 'type', 'carrier_id', 'carrier_service_code', 'description', 'delivery_days_min',
        'delivery_days_max', 'pickup_street', 'pickup_number', 'pickup_complement', 'pickup_district',
        'pickup_city', 'pickup_state', 'pickup_postal_code', 'pickup_instructions', 'is_active', 'position',
        'weight_basis', 'cubic_divisor', 'handling_days', 'accepts_free_shipping_coupon', 'pickup_opening_hours',
    ];

    protected function casts(): array
    {
        return [
            'type' => ShippingMethodType::class,
            'weight_basis' => WeightBasis::class,
            'delivery_days_min' => 'integer',
            'delivery_days_max' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
            'cubic_divisor' => 'integer',
            'handling_days' => 'integer',
            'accepts_free_shipping_coupon' => 'boolean',
        ];
    }

    /** @return BelongsTo<ShippingCarrier, $this> */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'carrier_id');
    }

    /** @return HasMany<ShippingRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(ShippingRule::class, 'method_id');
    }

    protected static function newFactory(): ShippingMethodFactory
    {
        return ShippingMethodFactory::new();
    }
}
