<?php

declare(strict_types=1);

namespace Database\Factories\Shipping;

use App\Modules\Shipping\Models\ShippingZone;
use App\Modules\Shipping\Models\ShippingZonePostalRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingZonePostalRange> */
class ShippingZonePostalRangeFactory extends Factory
{
    protected $model = ShippingZonePostalRange::class;

    public function definition(): array
    {
        return [
            'zone_id' => ShippingZone::factory(),
            'start_postal_code' => '89000000',
            'end_postal_code' => '89099999',
        ];
    }
}
