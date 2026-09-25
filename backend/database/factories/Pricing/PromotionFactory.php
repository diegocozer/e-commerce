<?php

declare(strict_types=1);

namespace Database\Factories\Pricing;

use App\Modules\Pricing\Enums\PromotionDiscountType;
use App\Modules\Pricing\Enums\PromotionScope;
use App\Modules\Pricing\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Promotion> */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        return [
            'name' => 'Promoção '.fake()->word(),
            'description' => fake()->sentence(),
            'discount_type' => PromotionDiscountType::Percent,
            'value' => 1000,
            'scope' => PromotionScope::Targeted,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
            'is_active' => true,
            'priority' => 100,
        ];
    }

    public function storeWide(): static
    {
        return $this->state(['scope' => PromotionScope::All]);
    }
}
