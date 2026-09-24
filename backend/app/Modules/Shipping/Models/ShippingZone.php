<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Database\Factories\Shipping\ShippingZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shipping zone: union of postal code ranges, IBGE cities and states (DB-14).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 */
class ShippingZone extends Model
{
    /** @use HasFactory<ShippingZoneFactory> */
    use HasFactory;

    protected $table = 'shipping_zones';

    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<ShippingZonePostalRange, $this> */
    public function postalRanges(): HasMany
    {
        return $this->hasMany(ShippingZonePostalRange::class, 'zone_id');
    }

    /** @return HasMany<ShippingZoneCity, $this> */
    public function cities(): HasMany
    {
        return $this->hasMany(ShippingZoneCity::class, 'zone_id');
    }

    /** @return HasMany<ShippingZoneState, $this> */
    public function states(): HasMany
    {
        return $this->hasMany(ShippingZoneState::class, 'zone_id');
    }

    /** @return HasMany<ShippingRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(ShippingRule::class, 'zone_id');
    }

    protected static function newFactory(): ShippingZoneFactory
    {
        return ShippingZoneFactory::new();
    }
}
