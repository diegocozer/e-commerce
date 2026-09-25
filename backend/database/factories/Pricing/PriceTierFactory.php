<?php

declare(strict_types=1);

namespace Database\Factories\Pricing;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Pricing\Models\PriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceTier> */
class PriceTierFactory extends Factory
{
    protected $model = PriceTier::class;

    public function definition(): array
    {
        return [
            'variant_id' => ProductVariant::factory(),
            'price_list_id' => null,
            'min_quantity' => '10.000',
            'price_cents' => fake()->numberBetween(100, 10000),
        ];
    }
}
