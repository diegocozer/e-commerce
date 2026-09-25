<?php

declare(strict_types=1);

namespace Database\Factories\Shipping;

use App\Modules\Shipping\Models\ShippingCarrier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingCarrier> */
class ShippingCarrierFactory extends Factory
{
    protected $model = ShippingCarrier::class;

    public function definition(): array
    {
        return [
            'name' => 'Transportadora '.fake()->word(),
            'code' => 'carrier_'.fake()->unique()->numerify('#####'),
            'driver' => 'fake',
            'credentials' => null,
            'settings' => [],
            'is_active' => true,
        ];
    }
}
