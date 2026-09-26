<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Contracts\CustomerStatsProvider;
use App\Modules\Customers\Models\Company;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Notifications\CustomerResetPasswordNotification;
use App\Modules\Identity\Enums\AdminPermission as P;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Pricing\Models\PriceList;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Identity\Support\ApiTestCase;

final class AdminCustomerTest extends ApiTestCase
{
    public function test_customer_session_cannot_access_the_panel(): void
    {
        $this->actingAsCustomer();
        $this->assertApiError($this->getJson('/api/v1/admin/customers'), 401, 'unauthenticated');
    }

    public function test_read_endpoints_require_customers_view(): void
    {
        $customer = $this->customer();
        $company = Company::factory()->create();
        $this->actingAsAdmin([P::OrdersView]);

        foreach (['/api/v1/admin/customers', "/api/v1/admin/customers/{$customer->id}", '/api/v1/admin/companies', "/api/v1/admin/companies/{$company->id}"] as $url) {
            $this->assertApiError($this->getJson($url), 403, 'forbidden');
        }
    }

    public function test_lists_customers_masked_with_filters(): void
    {
        $ana = $this->customer(['name' => 'Ana Souza', 'cpf' => '52998224725', 'email' => 'ana@example.com']);
        $pj = Customer::factory()->company()->create(['name' => 'Bruno Compras']);
        $this->customer(['name' => 'Carlos Inativo', 'is_active' => false]);
        $this->actingAsAdmin([P::CustomersView]);

        $this->getJson('/api/v1/admin/customers?sort=name')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('data.0.name', 'Ana Souza')
            ->assertJsonPath('data.0.cpf_masked', '***.982.247-**')
            ->assertJsonMissingPath('data.0.cpf')
            ->assertJsonMissingPath('data.0.addresses')
            ->assertJsonPath('data.0.stats.orders_count', 0);

        $this->getJson('/api/v1/admin/customers?q=529.982.247-25')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ana->id);
        $this->getJson('/api/v1/admin/customers?q=ana@exa')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/customers?q='.urlencode($pj->company->cnpj))->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.cnpj_masked', fn ($v) => str_starts_with($v, '**.'));
        $this->getJson('/api/v1/admin/customers?type=company')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/customers?is_active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Carlos Inativo');
        $this->getJson('/api/v1/admin/customers?sort=-total_spent')->assertOk();
        $this->getJson('/api/v1/admin/customers?sort=-last_order_at')->assertOk();
        $this->getJson('/api/v1/admin/customers?sort=password')->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/admin/customers?per_page=101')->assertJsonValidationErrors('per_page');
    }

    public function test_show_includes_addresses_and_stats_from_provider(): void
    {
        $customer = $this->customer();
        CustomerAddress::factory()->default()->create(['customer_id' => $customer->id]);
        $this->app->instance(CustomerStatsProvider::class, new class implements CustomerStatsProvider
        {
            public function statsFor(array $customerIds): array
            {
                return array_fill_keys($customerIds, ['orders_count' => 3, 'paid_total_cents' => 7950, 'last_order_at' => '2026-09-01T10:00:00Z']);
            }

            public function hasOrdersInProgress(int $customerId): bool
            {
                return true;
            }

            public function hasPaidOrders(int $customerId): bool
            {
                return true;
            }
        });
        $this->actingAsAdmin([P::CustomersView]);

        $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonCount(1, 'data.addresses')
            ->assertJsonPath('data.stats.orders_count', 3)
            ->assertJsonPath('data.stats.total_spent_cents', 7950);
        $this->getJson('/api/v1/admin/customers/999999')->assertNotFound();
    }

    public function test_update_field_level_permissions(): void
    {
        $customer = $this->customer(['name' => 'Nome Antigo']);
        $list = PriceList::factory()->create();

        $this->actingAsAdmin([P::CustomersView]);
        $this->assertApiError($this->patchJson("/api/v1/admin/customers/{$customer->id}", ['name' => 'Novo Nome']), 403, 'forbidden');

        $this->app['session.store']->flush();
        $this->actingAsAdmin([P::CustomersUpdate]);
        $this->assertApiError($this->patchJson("/api/v1/admin/customers/{$customer->id}", ['name' => 'Novo Nome', 'price_list_id' => $list->id]), 403, 'forbidden');
        $this->assertApiError($this->patchJson("/api/v1/admin/customers/{$customer->id}", ['cpf' => '52998224725']), 403, 'forbidden');
        self::assertSame('Nome Antigo', $customer->refresh()->name);
        self::assertNull($customer->price_list_id);

        $this->patchJson("/api/v1/admin/customers/{$customer->id}", ['name' => 'Novo Nome', 'phone' => '47 3333-2222', 'nickname' => 'ignored'])->assertOk()
            ->assertJsonPath('data.name', 'Novo Nome')->assertJsonPath('data.phone', '4733332222');
        foreach (['email' => 'x@example.com', 'type' => 'company', 'password' => 'abc', 'is_active' => false, 'marketing_opt_in' => true] as $f => $v) {
            $this->patchJson("/api/v1/admin/customers/{$customer->id}", [$f => $v])->assertJsonValidationErrors($f);
        }

        $this->app['session.store']->flush();
        $this->actingAsAdmin([P::PricingManage]);
        $this->patchJson("/api/v1/admin/customers/{$customer->id}", ['price_list_id' => $list->id])->assertOk()
            ->assertJsonPath('data.price_list.id', $list->id)->assertJsonPath('data.effective_price_list.id', $list->id);
        $this->patchJson("/api/v1/admin/customers/{$customer->id}", ['price_list_id' => 999999])->assertJsonValidationErrors('price_list_id');

        $this->app['session.store']->flush();
        $this->actingAsAdmin([P::CustomersManage]);
        $this->patchJson("/api/v1/admin/customers/{$customer->id}", ['cpf' => '529.982.247-25'])->assertOk()
            ->assertJsonPath('data.cpf_masked', '***.982.247-**');

        $log = AuditLog::query()->where('action', 'customer.updated')->where('auditable_id', $customer->id)->latest('id')->firstOrFail();
        self::assertSame('***.982.247-**', $log->new_values['cpf']);
    }

    public function test_cpf_correction_blocked_after_paid_order(): void
    {
        $customer = $this->customer();
        $this->app->instance(CustomerStatsProvider::class, new class implements CustomerStatsProvider
        {
            public function statsFor(array $customerIds): array
            {
                return [];
            }

            public function hasOrdersInProgress(int $customerId): bool
            {
                return false;
            }

            public function hasPaidOrders(int $customerId): bool
            {
                return true;
            }
        });
        $this->actingAsAdmin([P::CustomersManage]);
        $this->patchJson("/api/v1/admin/customers/{$customer->id}", ['cpf' => '52998224725'])->assertJsonValidationErrors('cpf');
    }

    public function test_block_and_unblock(): void
    {
        $customer = $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123'])->assertOk();
        $customerSession = $this->sessionId();

        $this->useSession('admin-browser-00000000000000000000000000');
        $this->actingAsAdmin([P::CustomersView, P::CustomersUpdate]);
        $this->postJson("/api/v1/admin/customers/{$customer->id}/block", [])->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/customers/{$customer->id}/block", ['reason' => 'Fraude suspeita'])->assertOk()->assertJsonPath('data.is_active', false);

        self::assertTrue(AuditLog::query()->where('action', 'customer.blocked')->where('auditable_id', $customer->id)->exists());
        $this->useSession($customerSession)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertApiError($this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123']), 403, 'account_disabled');

        $this->useSession('admin-browser-00000000000000000000000001');
        $this->actingAsAdmin([P::CustomersUpdate]);
        $this->postJson("/api/v1/admin/customers/{$customer->id}/unblock")->assertOk()->assertJsonPath('data.is_active', true);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123'])->assertOk();
    }

    public function test_block_requires_customers_update(): void
    {
        $customer = $this->customer();
        $this->actingAsAdmin([P::CustomersView], AdminRole::Warehouse);
        $this->assertApiError($this->postJson("/api/v1/admin/customers/{$customer->id}/block", ['reason' => 'abc']), 403, 'forbidden');
        $this->assertApiError($this->postJson("/api/v1/admin/customers/{$customer->id}/password-reset"), 403, 'forbidden');
        self::assertTrue($customer->refresh()->is_active);
    }

    public function test_password_reset_link(): void
    {
        Notification::fake();
        $customer = $this->customer();
        $this->actingAsAdmin([], AdminRole::Seller);

        $this->postJson("/api/v1/admin/customers/{$customer->id}/password-reset")->assertStatus(202);
        Notification::assertSentTo($customer, CustomerResetPasswordNotification::class);
    }

    public function test_reveal_document_is_audited_and_needs_permission(): void
    {
        $customer = $this->customer(['cpf' => '52998224725']);
        $this->actingAsAdmin([P::CustomersView]);
        $this->assertApiError($this->postJson("/api/v1/admin/customers/{$customer->id}/reveal-document"), 403, 'forbidden');

        $this->app['session.store']->flush();
        $admin = $this->actingAsAdmin([P::CustomersViewSensitive]);
        $this->postJson("/api/v1/admin/customers/{$customer->id}/reveal-document")->assertOk()
            ->assertExactJson(['data' => ['cpf' => '52998224725', 'cnpj' => null]]);

        self::assertTrue(AuditLog::query()->where('action', 'customer.document_revealed')->where('actor_id', $admin->id)->exists());
    }

    public function test_anonymize(): void
    {
        $customer = $this->customer(['cpf' => '52998224725', 'email' => 'ana@example.com']);
        $address = CustomerAddress::factory()->default()->create(['customer_id' => $customer->id, 'recipient_name' => 'Ana Souza']);

        $this->actingAsAdmin([P::CustomersUpdate]);
        $this->assertApiError($this->postJson("/api/v1/admin/customers/{$customer->id}/anonymize", ['confirm' => true]), 403, 'forbidden');

        $this->app['session.store']->flush();
        $this->actingAsAdmin([P::CustomersManage, P::CustomersView]);
        $this->postJson("/api/v1/admin/customers/{$customer->id}/anonymize", [])->assertJsonValidationErrors('confirm');
        $this->postJson("/api/v1/admin/customers/{$customer->id}/anonymize", ['confirm' => true])->assertOk()
            ->assertJsonPath('data.name', 'Cliente removido')
            ->assertJsonPath('data.cpf_masked', null)
            ->assertJsonPath('data.is_active', false);

        $customer = Customer::withTrashed()->findOrFail($customer->id);
        self::assertSame("removed+{$customer->uuid}@invalid.local", $customer->email);
        self::assertNotNull($customer->anonymized_at);
        self::assertNotNull($customer->deleted_at);
        $address = CustomerAddress::withTrashed()->findOrFail($address->id);
        self::assertSame('Removido', $address->recipient_name);
        self::assertNotNull($address->deleted_at);
        self::assertTrue(AuditLog::query()->where('action', 'customer.anonymized')->exists());
    }

    public function test_anonymize_blocked_with_orders_in_progress(): void
    {
        $customer = $this->customer();
        $this->app->instance(CustomerStatsProvider::class, new class implements CustomerStatsProvider
        {
            public function statsFor(array $customerIds): array
            {
                return [];
            }

            public function hasOrdersInProgress(int $customerId): bool
            {
                return true;
            }

            public function hasPaidOrders(int $customerId): bool
            {
                return false;
            }
        });
        $this->actingAsAdmin([P::CustomersManage]);
        $this->assertApiError($this->postJson("/api/v1/admin/customers/{$customer->id}/anonymize", ['confirm' => true]), 409, 'resource_in_use');
        self::assertNull($customer->refresh()->anonymized_at);
    }

    public function test_companies(): void
    {
        $company = Company::factory()->create(['legal_name' => 'Alfa Gráfica Ltda']);
        Customer::factory()->company()->create(['company_id' => $company->id]);
        Company::factory()->create(['legal_name' => 'Beta Placas Ltda']);
        $list = PriceList::factory()->create();

        $this->actingAsAdmin([P::CustomersView, P::CustomersUpdate]);
        $this->getJson('/api/v1/admin/companies')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.legal_name', 'Alfa Gráfica Ltda')
            ->assertJsonCount(1, 'data.0.customers')
            ->assertJsonMissingPath('data.0.cnpj');
        $this->getJson('/api/v1/admin/companies?q=beta')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/companies/{$company->id}")->assertOk()->assertJsonPath('data.id', $company->id);

        $this->patchJson("/api/v1/admin/companies/{$company->id}", ['trade_name' => 'Alfa', 'state_registration' => 'isento'])->assertOk()
            ->assertJsonPath('data.trade_name', 'Alfa')->assertJsonPath('data.state_registration_exempt', true);
        $this->assertApiError($this->patchJson("/api/v1/admin/companies/{$company->id}", ['price_list_id' => $list->id]), 403, 'forbidden');
        $this->assertApiError($this->patchJson("/api/v1/admin/companies/{$company->id}", ['cnpj' => '11222333000181']), 403, 'forbidden');

        $this->app['session.store']->flush();
        $this->actingAsAdmin([P::PricingManage, P::CustomersManage]);
        $this->patchJson("/api/v1/admin/companies/{$company->id}", ['price_list_id' => $list->id, 'cnpj' => '11.222.333/0001-81'])->assertOk()
            ->assertJsonPath('data.price_list.id', $list->id)->assertJsonPath('data.cnpj_masked', '**.222.333/0001-**');
        $this->patchJson("/api/v1/admin/companies/{$company->id}", ['cnpj' => '11222333000180'])->assertJsonValidationErrors('cnpj');
        self::assertTrue(AuditLog::query()->where('action', 'company.updated')->where('auditable_id', $company->id)->exists());
    }
}
