<?php

declare(strict_types=1);

namespace Database\Factories\Shipping;

use App\Modules\Shipping\Models\IbgeCity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IbgeCity> */
class IbgeCityFactory extends Factory
{
    protected $model = IbgeCity::class;

    public function definition(): array
    {
        return [
            'ibge_code' => '9'.fake()->unique()->numerify('######'),
            'name' => fake()->city(),
            'state' => 'SC',
        ];
    }
}
