<?php

declare(strict_types=1);

namespace Database\Factories\Shipping;

use App\Modules\Shipping\Enums\ShippingPriceType;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingRule;
use App\Modules\Shipping\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingRule> */
class ShippingRuleFactory extends Factory
{
    protected $model = ShippingRule::class;

    public function definition(): array
    {
        return [
            'method_id' => ShippingMethod::factory(),
            'zone_id' => ShippingZone::factory(),
            'name' => 'Regra '.fake()->word(),
            'priority' => 100,
            'max_weight_grams' => 30000,
            'price_type' => ShippingPriceType::Fixed,
            'price_cents' => 2000,
            'per_kg_cents' => 0,
            'percentage_bp' => 0,
            'is_active' => true,
        ];
    }

    public function free(): static
    {
        return $this->state(['price_type' => ShippingPriceType::Free, 'price_cents' => 0]);
    }
}
