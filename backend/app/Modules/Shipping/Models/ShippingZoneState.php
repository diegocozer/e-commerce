<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Database\Factories\Shipping\ShippingZoneStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Location of a shipping zone (DATABASE.md §3.8.3).
 *
 * @property int $id
 * @property int $zone_id
 * @property string $state
 */
class ShippingZoneState extends Model
{
    /** @use HasFactory<ShippingZoneStateFactory> */
    use HasFactory;

    protected $table = 'shipping_zone_states';

    protected $fillable = ['zone_id', 'state'];

    /** @return BelongsTo<ShippingZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'zone_id');
    }

    protected static function newFactory(): ShippingZoneStateFactory
    {
        return ShippingZoneStateFactory::new();
    }
}
