<?php

declare(strict_types=1);

namespace Database\Factories\Cart;

use App\Modules\Cart\Models\Cart;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Default: guest cart. @extends Factory<Cart> */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'customer_id' => null,
            'postal_code' => null,
            'coupon_id' => null,
            'expires_at' => now()->addDays(30),
        ];
    }

    public function forCustomer(?Customer $customer = null): static
    {
        return $this->state(['customer_id' => $customer?->id ?? Customer::factory()]);
    }
}
