<?php

declare(strict_types=1);

namespace Database\Factories\Customers;

use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Customers\Models\Company;
use App\Modules\Customers\Models\Customer;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'type' => CustomerType::Individual,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'cpf' => BrazilianDocuments::cpf(),
            'phone' => BrazilianDocuments::phone(),
            'company_id' => null,
            'price_list_id' => null,
            'email_verified_at' => now(),
            'marketing_opt_in' => false,
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
            'terms_accepted_ip' => fake()->ipv4(),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function company(): static
    {
        return $this->state(fn () => [
            'type' => CustomerType::Company,
            'cpf' => null,
            'company_id' => Company::factory(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
