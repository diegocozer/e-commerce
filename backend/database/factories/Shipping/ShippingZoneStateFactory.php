<?php

declare(strict_types=1);

namespace Database\Factories\Shipping;

use App\Modules\Shipping\Models\ShippingZone;
use App\Modules\Shipping\Models\ShippingZoneState;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingZoneState> */
class ShippingZoneStateFactory extends Factory
{
    protected $model = ShippingZoneState::class;

    public function definition(): array
    {
        return [
            'zone_id' => ShippingZone::factory(),
            'state' => 'SC',
        ];
    }
}
