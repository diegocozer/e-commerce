<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Database\Factories\Shipping\ShippingCarrierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * External carrier (DATABASE.md §3.8.1). `credentials` is encrypted (DB-13)
 * and never returned by the API.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $driver
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed> $settings
 * @property bool $is_active
 */
class ShippingCarrier extends Model
{
    /** @use HasFactory<ShippingCarrierFactory> */
    use HasFactory;

    protected $table = 'shipping_carriers';

    protected $fillable = ['name', 'code', 'driver', 'credentials', 'settings', 'is_active'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ShippingMethod, $this> */
    public function methods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class, 'carrier_id');
    }

    protected static function newFactory(): ShippingCarrierFactory
    {
        return ShippingCarrierFactory::new();
    }
}
