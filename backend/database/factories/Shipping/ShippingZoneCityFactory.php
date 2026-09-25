<?php

declare(strict_types=1);

namespace Database\Factories\Shipping;

use App\Modules\Shipping\Models\ShippingZone;
use App\Modules\Shipping\Models\ShippingZoneCity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingZoneCity> */
class ShippingZoneCityFactory extends Factory
{
    protected $model = ShippingZoneCity::class;

    public function definition(): array
    {
        return [
            'zone_id' => ShippingZone::factory(),
            'city_ibge_code' => '4202404',
            'city_name' => 'Blumenau',
            'state' => 'SC',
        ];
    }
}
