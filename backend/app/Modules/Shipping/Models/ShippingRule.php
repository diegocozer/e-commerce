<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Shipping\Enums\ShippingPriceType;
use Database\Factories\Shipping\ShippingRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Condition + price for own_delivery/table_rate methods (DATABASE.md §3.8.4).
 * `zone_id` NULL = any destination covered by the method.
 *
 * @property int $id
 * @property int $method_id
 * @property int|null $zone_id
 * @property string $name
 * @property int $priority
 * @property ShippingPriceType $price_type
 * @property int $price_cents
 * @property int $per_kg_cents
 * @property int $percentage_bp
 * @property bool $is_active
 */
class ShippingRule extends Model
{
    /** @use HasFactory<ShippingRuleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'shipping_rules';

    protected $fillable = [
        'method_id', 'zone_id', 'name', 'priority', 'min_weight_grams', 'max_weight_grams', 'min_subtotal_cents',
        'max_subtotal_cents', 'min_volume_cm3', 'max_volume_cm3', 'max_package_length_cm', 'price_type',
        'price_cents', 'per_kg_cents', 'percentage_bp', 'min_price_cents', 'max_price_cents', 'delivery_days_min',
        'delivery_days_max', 'valid_from', 'valid_until', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'min_weight_grams' => 'integer',
            'max_weight_grams' => 'integer',
            'min_subtotal_cents' => 'integer',
            'max_subtotal_cents' => 'integer',
            'min_volume_cm3' => 'integer',
            'max_volume_cm3' => 'integer',
            'max_package_length_cm' => 'decimal:1',
            'price_type' => ShippingPriceType::class,
            'price_cents' => 'integer',
            'per_kg_cents' => 'integer',
            'percentage_bp' => 'integer',
            'min_price_cents' => 'integer',
            'max_price_cents' => 'integer',
            'delivery_days_min' => 'integer',
            'delivery_days_max' => 'integer',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ShippingMethod, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'method_id');
    }

    /** @return BelongsTo<ShippingZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'zone_id');
    }

    protected static function newFactory(): ShippingRuleFactory
    {
        return ShippingRuleFactory::new();
    }
}
