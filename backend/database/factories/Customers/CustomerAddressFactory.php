<?php

declare(strict_types=1);

namespace Database\Factories\Customers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAddress> */
class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => fake()->randomElement(['Casa', 'Loja', 'Gráfica', null]),
            'recipient_name' => fake()->name(),
            'phone' => null,
            'postal_code' => '890'.fake()->numerify('#####'),
            'street' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'complement' => null,
            'district' => fake()->randomElement(['Centro', 'Victor Konder', 'Vorstadt', 'Garcia', 'Itoupava Norte']),
            'city' => 'Blumenau',
            'state' => 'SC',
            'city_ibge_code' => '4202404',
            'reference' => null,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
