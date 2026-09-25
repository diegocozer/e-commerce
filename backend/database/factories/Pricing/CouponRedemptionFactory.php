<?php

declare(strict_types=1);

namespace Database\Factories\Pricing;

use App\Modules\Orders\Models\Order;
use App\Modules\Pricing\Models\Coupon;
use App\Modules\Pricing\Models\CouponRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CouponRedemption> */
class CouponRedemptionFactory extends Factory
{
    protected $model = CouponRedemption::class;

    public function definition(): array
    {
        return [
            'coupon_id' => Coupon::factory(),
            'order_id' => Order::factory(),
            'customer_id' => fn (array $attributes): int => Order::query()->findOrFail($attributes['order_id'])->customer_id,
            'discount_cents' => 1000,
        ];
    }
}
