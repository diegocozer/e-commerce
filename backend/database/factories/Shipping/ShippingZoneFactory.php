<?php

declare(strict_types=1);

namespace Database\Factories\Shipping;

use App\Modules\Shipping\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingZone> */
class ShippingZoneFactory extends Factory
{
    protected $model = ShippingZone::class;

    public function definition(): array
    {
        return [
            'name' => 'Zona '.fake()->unique()->city(),
            'description' => null,
            'is_active' => true,
        ];
    }
}
