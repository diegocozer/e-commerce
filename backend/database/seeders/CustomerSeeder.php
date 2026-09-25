<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Customers\Models\Company;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Pricing\Models\PriceList;
use Illuminate\Database\Seeder;

/** DATABASE.md §7.9 — one PF and one PJ customer with addresses. */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $maria = Customer::query()->updateOrCreate(['email' => 'maria@example.com'], [
            'type' => CustomerType::Individual,
            'name' => 'Maria da Silva',
            'password' => 'password',
            'cpf' => '52998224725',
            'phone' => '47999990001',
            'email_verified_at' => now(),
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
            'is_active' => true,
        ]);
        $this->address($maria, 'Casa', [
            'recipient_name' => 'Maria da Silva', 'street' => 'Rua das Palmeiras', 'number' => '123', 'district' => 'Victor Konder',
            'city' => 'Blumenau', 'state' => 'SC', 'postal_code' => '89012000', 'city_ibge_code' => '4202404',
        ], true);

        $company = Company::query()->updateOrCreate(['cnpj' => '11222333000181'], [
            'legal_name' => 'Gráfica Exemplo Ltda',
            'trade_name' => 'Gráfica Exemplo',
            'state_registration' => '255123456',
            'state_registration_exempt' => false,
            'price_list_id' => PriceList::query()->where('code', 'reseller')->value('id'),
        ]);

        $joao = Customer::query()->updateOrCreate(['email' => 'compras@graficaexemplo.com.br'], [
            'type' => CustomerType::Company,
            'name' => 'João Pereira',
            'password' => 'password',
            'cpf' => null,
            'phone' => '47999990002',
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
            'is_active' => true,
        ]);
        $this->address($joao, 'Gráfica', [
            'recipient_name' => 'João Pereira', 'street' => 'Rua Itajaí', 'number' => '500', 'district' => 'Vorstadt',
            'city' => 'Blumenau', 'state' => 'SC', 'postal_code' => '89015200', 'city_ibge_code' => '4202404',
        ], true);
        $this->address($joao, 'Filial Joinville', [
            'recipient_name' => 'João Pereira', 'street' => 'Rua do Príncipe', 'number' => '100', 'district' => 'Centro',
            'city' => 'Joinville', 'state' => 'SC', 'postal_code' => '89201000', 'city_ibge_code' => '4209102',
        ], false);
    }

    /** @param  array<string, string>  $data */
    private function address(Customer $customer, string $label, array $data, bool $default): void
    {
        CustomerAddress::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'label' => $label],
            [...$data, 'is_default' => $default],
        );
    }
}
