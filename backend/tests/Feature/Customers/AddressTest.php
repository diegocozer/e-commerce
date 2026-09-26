<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Tests\Feature\Identity\Support\ApiTestCase;

final class AddressTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'postal_code' => '89012-000', 'street' => 'Rua das Palmeiras', 'number' => '123', 'district' => 'Victor Konder',
            'recipient_name' => 'Ana Souza', 'phone' => '(47) 99999-1111', 'label' => 'Loja', ...$overrides,
        ];
    }

    public function test_creates_the_first_address_as_default_with_city_derived_from_cep(): void
    {
        $customer = $this->actingAsCustomer();

        $response = $this->postJson('/api/v1/me/addresses', $this->payload(['city' => 'Hackerville', 'state' => 'XX', 'city_ibge_code' => '0000000']))
            ->assertCreated()
            ->assertJsonPath('data.postal_code', '89012000')
            ->assertJsonPath('data.city', 'Blumenau')
            ->assertJsonPath('data.state', 'SC')
            ->assertJsonPath('data.city_ibge_code', '4202404')
            ->assertJsonPath('data.phone', '47999991111')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.formatted', 'Rua das Palmeiras, 123 – Victor Konder – Blumenau/SC – 89012-000')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.customer_id');

        self::assertSame($customer->id, CustomerAddress::query()->where('uuid', $response->json('data.uuid'))->value('customer_id'));
    }

    public function test_new_default_unsets_the_previous_one_and_list_is_ordered(): void
    {
        $customer = $this->actingAsCustomer();
        $first = $this->postJson('/api/v1/me/addresses', $this->payload())->json('data.uuid');
        $second = $this->postJson('/api/v1/me/addresses', $this->payload(['postal_code' => '01310100', 'is_default' => true]))
            ->assertJsonPath('data.city', 'São Paulo')->assertJsonPath('data.is_default', true)->json('data.uuid');
        $third = $this->postJson('/api/v1/me/addresses', $this->payload(['number' => 's/n']))
            ->assertJsonPath('data.number', 'S/N')->assertJsonPath('data.is_default', false)->json('data.uuid');

        $this->getJson('/api/v1/me/addresses')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.uuid', $second)
            ->assertJsonPath('data.1.uuid', $third);

        $this->postJson("/api/v1/me/addresses/{$first}/default")->assertOk()->assertJsonPath('data.is_default', true);
        self::assertSame(1, $customer->addresses()->where('is_default', true)->count());
    }

    public function test_updates_partially_and_recomputes_city_on_cep_change(): void
    {
        $this->actingAsCustomer();
        $uuid = $this->postJson('/api/v1/me/addresses', $this->payload())->json('data.uuid');

        $this->patchJson("/api/v1/me/addresses/{$uuid}", ['complement' => 'Sala 2'])->assertOk()
            ->assertJsonPath('data.complement', 'Sala 2')->assertJsonPath('data.street', 'Rua das Palmeiras');
        $this->patchJson("/api/v1/me/addresses/{$uuid}", ['postal_code' => '01310100'])->assertOk()
            ->assertJsonPath('data.city', 'São Paulo')->assertJsonPath('data.state', 'SP');
        $this->getJson("/api/v1/me/addresses/{$uuid}")->assertOk()->assertJsonPath('data.city_ibge_code', '3550308');
    }

    public function test_delete_default_promotes_the_most_recent(): void
    {
        $customer = $this->actingAsCustomer();
        $first = $this->postJson('/api/v1/me/addresses', $this->payload())->json('data.uuid');
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/me/addresses', $this->payload(['label' => 'Obra']));
        $this->travel(1)->minutes();
        $latest = $this->postJson('/api/v1/me/addresses', $this->payload(['label' => 'Casa']))->json('data.uuid');

        $this->deleteJson("/api/v1/me/addresses/{$first}")->assertNoContent();

        self::assertSoftDeleted('customer_addresses', ['uuid' => $first]);
        self::assertTrue($customer->addresses()->where('uuid', $latest)->value('is_default'));
        $this->getJson("/api/v1/me/addresses/{$first}")->assertNotFound();
    }

    public function test_limit_of_ten_addresses(): void
    {
        $customer = $this->actingAsCustomer();
        CustomerAddress::factory()->count(10)->create(['customer_id' => $customer->id]);

        $this->postJson('/api/v1/me/addresses', $this->payload())
            ->assertJsonValidationErrors(['address' => 'Limite de 10 endereços.']);
    }

    public function test_cep_not_found_and_lookup_unavailable(): void
    {
        $customer = $this->actingAsCustomer();

        $this->postJson('/api/v1/me/addresses', $this->payload(['postal_code' => '99999-999']))
            ->assertJsonValidationErrors(['postal_code' => 'CEP não encontrado.']);
        $this->assertApiError($this->postJson('/api/v1/me/addresses', $this->payload(['postal_code' => '11111-111'])), 503, 'postal_code_lookup_unavailable');
        $this->postJson('/api/v1/me/addresses', $this->payload(['postal_code' => '123']))->assertJsonValidationErrors('postal_code');

        self::assertSame(0, $customer->addresses()->count());
    }

    public function test_validation_and_prohibited_fields(): void
    {
        $customer = $this->actingAsCustomer();
        $this->postJson('/api/v1/me/addresses', [])->assertJsonValidationErrors(['postal_code', 'street', 'number', 'district', 'recipient_name', 'phone']);
        $other = Customer::factory()->create();
        $this->postJson('/api/v1/me/addresses', $this->payload(['customer_id' => $other->id]))->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/v1/me/addresses', $this->payload(['uuid' => '0194a5a2-0000-7000-8000-000000000000']))->assertJsonValidationErrors('uuid');
        self::assertSame(0, CustomerAddress::query()->count());
        self::assertSame(0, $customer->addresses()->count());
    }

    public function test_idor_other_customers_addresses_return_404_and_stay_intact(): void
    {
        $owner = $this->customer();
        $address = CustomerAddress::factory()->default()->create(['customer_id' => $owner->id, 'street' => 'Rua Original']);
        $this->actingAsCustomer();

        $this->assertApiError($this->getJson("/api/v1/me/addresses/{$address->uuid}"), 404, 'not_found');
        $this->assertApiError($this->patchJson("/api/v1/me/addresses/{$address->uuid}", ['street' => 'Hack']), 404, 'not_found');
        $this->assertApiError($this->deleteJson("/api/v1/me/addresses/{$address->uuid}"), 404, 'not_found');
        $this->assertApiError($this->postJson("/api/v1/me/addresses/{$address->uuid}/default"), 404, 'not_found');
        $this->getJson('/api/v1/me/addresses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/me/addresses/not-a-uuid')->assertNotFound();

        $address->refresh();
        self::assertSame('Rua Original', $address->street);
        self::assertTrue($address->is_default);
        self::assertNull($address->deleted_at);
    }
}
