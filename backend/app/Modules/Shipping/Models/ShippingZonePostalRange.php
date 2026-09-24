<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Database\Factories\Shipping\ShippingZonePostalRangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Location of a shipping zone (DATABASE.md §3.8.3).
 *
 * @property int $id
 * @property int $zone_id
 * @property string $start_postal_code
 * @property string $end_postal_code
 */
class ShippingZonePostalRange extends Model
{
    /** @use HasFactory<ShippingZonePostalRangeFactory> */
    use HasFactory;

    protected $table = 'shipping_zone_postal_ranges';

    protected $fillable = ['zone_id', 'start_postal_code', 'end_postal_code'];

    /** @return BelongsTo<ShippingZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'zone_id');
    }

    protected static function newFactory(): ShippingZonePostalRangeFactory
    {
        return ShippingZonePostalRangeFactory::new();
    }
}
