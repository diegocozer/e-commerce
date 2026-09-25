<?php

declare(strict_types=1);

namespace Database\Factories\Customers;

use App\Modules\Customers\Models\Company;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'legal_name' => $name.' Ltda',
            'trade_name' => $name,
            'cnpj' => BrazilianDocuments::cnpj(),
            'state_registration' => (string) fake()->numberBetween(100000000, 999999999),
            'state_registration_exempt' => false,
            'price_list_id' => null,
        ];
    }

    public function exempt(): static
    {
        return $this->state(['state_registration' => null, 'state_registration_exempt' => true]);
    }

    public function alphanumericCnpj(): static
    {
        return $this->state(fn () => ['cnpj' => BrazilianDocuments::alphanumericCnpj()]);
    }
}
