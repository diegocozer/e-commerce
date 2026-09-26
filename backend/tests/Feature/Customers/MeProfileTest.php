<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Models\Company;
use App\Modules\Customers\Models\Customer;
use App\Modules\Pricing\Models\PriceList;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Identity\Support\ApiTestCase;

final class MeProfileTest extends ApiTestCase
{
    public function test_me_endpoints_require_a_customer_session(): void
    {
        foreach ([['get', '/api/v1/me'], ['patch', '/api/v1/me'], ['put', '/api/v1/me/password'], ['patch', '/api/v1/me/company'],
            ['post', '/api/v1/me/terms-acceptance'], ['get', '/api/v1/me/addresses'], ['post', '/api/v1/me/addresses']] as [$m, $url]) {
            $this->assertApiError($this->json($m, $url), 401, 'unauthenticated');
        }
    }

    public function test_admin_session_does_not_grant_access_to_me(): void
    {
        $this->superAdmin();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_shows_the_profile_without_internal_ids(): void
    {
        $list = PriceList::factory()->create(['code' => 'atacado', 'name' => 'Atacado']);
        $customer = $this->customer(['phone' => null]);
        $customer->forceFill(['price_list_id' => $list->id])->save();
        $this->actingAsCustomer($customer);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.uuid', $customer->uuid)
            ->assertJsonPath('data.price_list', ['code' => 'atacado', 'name' => 'Atacado'])
            ->assertJsonPath('data.profile_complete', false)
            ->assertJsonPath('data.missing_fields', ['phone'])
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.company_id');
    }

    public function test_company_price_list_is_inherited(): void
    {
        $list = PriceList::factory()->create(['code' => 'revenda', 'name' => 'Revenda']);
        $customer = Customer::factory()->company()->create();
        $customer->company->forceFill(['price_list_id' => $list->id])->save();
        $this->actingAsCustomer($customer);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.price_list.code', 'revenda')
            ->assertJsonPath('data.company.cnpj', $customer->company->cnpj);
    }

    public function test_updates_whitelisted_fields(): void
    {
        $customer = $this->actingAsCustomer();

        $this->patchJson('/api/v1/me', ['name' => 'Ana Paula Souza', 'phone' => '(47) 3333-4444', 'marketing_opt_in' => true, 'nickname' => 'x'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ana Paula Souza')
            ->assertJsonPath('data.phone', '4733334444')
            ->assertJsonPath('data.marketing_opt_in', true);

        $customer->refresh();
        self::assertNotNull($customer->marketing_opt_in_at);
    }

    public function test_prohibited_fields_change_nothing(): void
    {
        $customer = $this->actingAsCustomer();
        $before = $customer->only(['name', 'email', 'type', 'is_active']);

        foreach (['id' => 1, 'customer_id' => 2, 'status' => 'x', 'email' => 'new@example.com', 'type' => 'company',
            'company' => ['cnpj' => '11222333000181'], 'is_active' => false, 'price_list_id' => 1, 'email_verified_at' => null] as $field => $value) {
            $this->patchJson('/api/v1/me', ['name' => 'Outro Nome Qualquer', $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        self::assertSame($before, $customer->refresh()->only(['name', 'email', 'type', 'is_active']));
    }

    public function test_cpf_can_only_be_filled_when_empty(): void
    {
        $customer = $this->actingAsCustomer();
        $this->patchJson('/api/v1/me', ['cpf' => '529.982.247-25'])
            ->assertJsonValidationErrors(['cpf' => 'CPF não pode ser alterado. Fale conosco.']);

        $pj = Customer::factory()->company()->create();
        $this->app['session.store']->flush(); // another browser
        $this->actingAsCustomer($pj);
        $this->patchJson('/api/v1/me', ['cpf' => '529.982.247-25'])->assertOk()->assertJsonPath('data.cpf', '52998224725');
        self::assertNotSame('52998224725', $customer->refresh()->cpf);
    }

    public function test_changes_password(): void
    {
        $customer = $this->actingAsCustomer($this->customer(['password' => 'segredo123']));

        $this->putJson('/api/v1/me/password', ['current_password' => 'errada', 'password' => 'novaSenha99', 'password_confirmation' => 'novaSenha99'])
            ->assertJsonValidationErrors(['current_password' => 'Senha atual incorreta.']);
        $this->putJson('/api/v1/me/password', ['current_password' => 'segredo123', 'password' => 'segredo123', 'password_confirmation' => 'segredo123'])
            ->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/me/password', ['current_password' => 'segredo123', 'password' => 'novaSenha99', 'password_confirmation' => 'novaSenha99'])
            ->assertNoContent();

        self::assertTrue(Hash::check('novaSenha99', $customer->refresh()->password));
    }

    public function test_password_change_keeps_current_session_and_drops_others(): void
    {
        $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123'])->assertOk();
        $other = $this->sessionId();
        $this->useSession($other)->getJson('/api/v1/me')->assertOk();

        $this->useSession('another-browser-0000000000000000000000000')
            ->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123'])->assertOk();
        $current = $this->sessionId();
        $this->useSession($current)->putJson('/api/v1/me/password', ['current_password' => 'segredo123', 'password' => 'novaSenha99', 'password_confirmation' => 'novaSenha99'])
            ->assertNoContent();
        $current = $this->sessionId();

        $this->useSession($current)->getJson('/api/v1/me')->assertOk();
        $this->useSession($other)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_company_data_update(): void
    {
        $customer = Customer::factory()->company()->create();
        $this->actingAsCustomer($customer);
        $cnpj = $customer->company->cnpj;

        $this->patchJson('/api/v1/me/company', ['legal_name' => 'Nova Razão Ltda', 'state_registration' => 'ISENTO'])
            ->assertOk()
            ->assertJsonPath('data.company.legal_name', 'Nova Razão Ltda')
            ->assertJsonPath('data.company.state_registration', null)
            ->assertJsonPath('data.company.state_registration_exempt', true);

        $this->patchJson('/api/v1/me/company', ['state_registration_exempt' => false])->assertJsonValidationErrors('state_registration');
        $this->patchJson('/api/v1/me/company', ['state_registration' => '987654'])->assertOk()
            ->assertJsonPath('data.company.state_registration', '987654')
            ->assertJsonPath('data.company.state_registration_exempt', false);

        $this->patchJson('/api/v1/me/company', ['cnpj' => '11222333000181'])->assertJsonValidationErrors('cnpj');
        self::assertSame($cnpj, Company::query()->find($customer->company_id)->cnpj);
    }

    public function test_company_update_is_forbidden_for_individuals(): void
    {
        $this->actingAsCustomer();
        $this->assertApiError($this->patchJson('/api/v1/me/company', ['legal_name' => 'X Ltda']), 403, 'forbidden');
    }

    public function test_terms_acceptance(): void
    {
        $customer = $this->actingAsCustomer($this->customer(['terms_version' => '2025-01']));
        $this->getJson('/api/v1/me')->assertJsonPath('data.terms.needs_acceptance', true);

        $this->postJson('/api/v1/me/terms-acceptance', ['terms_version' => '2025-01', 'accept_terms' => true])->assertJsonValidationErrors('terms_version');
        $this->postJson('/api/v1/me/terms-acceptance', ['terms_version' => '2026-01'])->assertJsonValidationErrors('accept_terms');
        $this->postJson('/api/v1/me/terms-acceptance', ['terms_version' => '2026-01', 'accept_terms' => true])
            ->assertOk()->assertJsonPath('data.terms.needs_acceptance', false);
        self::assertSame('2026-01', $customer->refresh()->terms_version);
    }
}
