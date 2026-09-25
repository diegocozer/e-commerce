<?php

declare(strict_types=1);

namespace Database\Factories\Pricing;

use App\Modules\Pricing\Enums\CouponType;
use App\Modules\Pricing\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Coupon> */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code' => 'CUPOM'.fake()->unique()->numerify('#####'),
            'description' => fake()->sentence(),
            'type' => CouponType::Percent,
            'value' => 1000,
            'min_order_cents' => 0,
            'max_discount_cents' => null,
            'starts_at' => null,
            'ends_at' => null,
            'usage_limit' => null,
            'usage_limit_per_customer' => null,
            'times_used' => 0,
            'is_active' => true,
        ];
    }

    public function fixed(int $cents = 2000): static
    {
        return $this->state(['type' => CouponType::Fixed, 'value' => $cents]);
    }

    public function freeShipping(): static
    {
        return $this->state(['type' => CouponType::FreeShipping, 'value' => 0]);
    }
}
