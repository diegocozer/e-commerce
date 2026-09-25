<?php

declare(strict_types=1);

namespace Database\Factories\Pricing;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Pricing\Models\CustomerPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerPrice> */
class CustomerPriceFactory extends Factory
{
    protected $model = CustomerPrice::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'company_id' => null,
            'variant_id' => ProductVariant::factory(),
            'price_cents' => fake()->numberBetween(100, 10000),
            'starts_at' => null,
            'ends_at' => null,
        ];
    }
}
