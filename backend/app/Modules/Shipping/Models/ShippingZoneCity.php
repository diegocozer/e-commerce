<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Database\Factories\Shipping\ShippingZoneCityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Location of a shipping zone (DATABASE.md §3.8.3).
 *
 * @property int $id
 * @property int $zone_id
 * @property string $city_ibge_code
 * @property string $city_name
 * @property string $state
 */
class ShippingZoneCity extends Model
{
    /** @use HasFactory<ShippingZoneCityFactory> */
    use HasFactory;

    protected $table = 'shipping_zone_cities';

    protected $fillable = ['zone_id', 'city_ibge_code', 'city_name', 'state'];

    /** @return BelongsTo<ShippingZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'zone_id');
    }

    protected static function newFactory(): ShippingZoneCityFactory
    {
        return ShippingZoneCityFactory::new();
    }
}
