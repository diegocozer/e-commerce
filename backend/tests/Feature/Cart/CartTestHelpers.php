<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Testing\TestResponse;

/** Seeded data helpers (DATABASE.md §7 seed: vinil VIN-BR-122-BR R$ 15,90/m, lona LON-FL-440-SM R$ 30,00/m²). */
trait CartTestHelpers
{
    /**
     * RefreshDatabase seeds only on the first migration of the whole run; when another
     * test class migrated first the seed data is missing. Seed inside the test
     * transaction in that case (rolled back afterwards), so order never matters.
     */
    protected function ensureSeeded(): void
    {
        if (! Customer::query()->where('email', 'maria@example.com')->exists()
            || ! ProductVariant::query()->where('sku', 'VIN-BR-122-BR')->exists()) {
            $this->seed(DatabaseSeeder::class);
        }
    }

    protected function variantId(string $sku): int
    {
        return (int) ProductVariant::query()->where('sku', $sku)->value('id');
    }

    protected function maria(): Customer
    {
        return Customer::query()->where('email', 'maria@example.com')->firstOrFail();
    }

    protected function joao(): Customer
    {
        return Customer::query()->where('email', 'compras@graficaexemplo.com.br')->firstOrFail();
    }

    protected function addressOf(Customer $customer): CustomerAddress
    {
        return CustomerAddress::query()->where('customer_id', $customer->id)->orderByDesc('is_default')->orderBy('id')->firstOrFail();
    }

    /** @param array<string, mixed> $body */
    protected function addItem(array $body, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/v1/cart/items', $body, $token !== null ? ['X-Cart-Token' => $token] : []);
    }
}
