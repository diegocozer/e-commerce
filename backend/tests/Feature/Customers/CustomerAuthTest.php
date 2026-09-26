<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Contracts\GuestCartMerger;
use App\Modules\Customers\DTOs\CartMergeReport;
use App\Modules\Customers\Events\CustomerAuthenticated;
use App\Modules\Customers\Events\CustomerPasswordReset;
use App\Modules\Customers\Events\CustomerRegistered;
use App\Modules\Customers\Models\Company;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Notifications\CustomerResetPasswordNotification;
use App\Modules\Customers\Notifications\CustomerVerifyEmailNotification;
use App\Modules\Customers\Support\EmailVerificationSignature;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Identity\Support\ApiTestCase;

final class CustomerAuthTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function pf(array $overrides = []): array
    {
        return [
            'type' => 'individual', 'name' => 'João da Silva', 'cpf' => '529.982.247-25', 'email' => 'Joao@Example.com',
            'phone' => '(47) 99999-0001', 'password' => 'segredo123', 'password_confirmation' => 'segredo123',
            'accept_terms' => true, 'terms_version' => '2026-01', ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function pj(array $company = [], array $overrides = []): array
    {
        return $this->pf([
            'type' => 'company', 'name' => 'Maria Compras', 'cpf' => null, 'email' => 'compras@grafica.com',
            'company' => ['cnpj' => '11.222.333/0001-81', 'legal_name' => 'Gráfica Exemplo Ltda', 'trade_name' => 'Gráfica Exemplo',
                'state_registration' => '123.456.789', 'state_registration_exempt' => false, ...$company],
            ...$overrides,
        ]);
    }

    public function test_registers_an_individual_customer_and_logs_in(): void
    {
        Notification::fake();
        Event::fake([CustomerRegistered::class, CustomerAuthenticated::class]);

        $response = $this->postJson('/api/v1/auth/register', $this->pf(['marketing_opt_in' => true]))
            ->assertCreated()
            ->assertJsonPath('data.customer.email', 'joao@example.com')
            ->assertJsonPath('data.customer.cpf', '52998224725')
            ->assertJsonPath('data.customer.phone', '47999990001')
            ->assertJsonPath('data.customer.type', 'individual')
            ->assertJsonPath('data.customer.terms.needs_acceptance', false)
            ->assertJsonPath('data.customer.profile_complete', true)
            ->assertJsonPath('data.cart_merge', null)
            ->assertJsonMissingPath('data.customer.id')
            ->assertHeader('Cache-Control', 'no-store, private');

        $customer = Customer::query()->where('email', 'joao@example.com')->firstOrFail();
        self::assertTrue($customer->marketing_opt_in);
        self::assertNotNull($customer->marketing_opt_in_at);
        self::assertSame('2026-01', $customer->terms_version);
        self::assertNotNull($customer->terms_accepted_at);
        self::assertNull($customer->email_verified_at);
        self::assertSame($response->json('data.customer.uuid'), $customer->uuid);
        $this->assertAuthenticatedAs($customer, 'customer');
        Notification::assertSentTo($customer, CustomerVerifyEmailNotification::class);
        Event::assertDispatched(CustomerRegistered::class, fn ($e) => $e->customerId === $customer->id);
        Event::assertDispatched(CustomerAuthenticated::class);
    }

    public function test_registers_a_company_with_alphanumeric_cnpj_and_exempt_ie(): void
    {
        $cnpj = BrazilianDocuments::alphanumericCnpj();

        $this->postJson('/api/v1/auth/register', $this->pj(['cnpj' => strtolower($cnpj), 'state_registration' => 'isento', 'state_registration_exempt' => false]))
            ->assertCreated()
            ->assertJsonPath('data.customer.type', 'company')
            ->assertJsonPath('data.customer.cpf', null)
            ->assertJsonPath('data.customer.company.cnpj', $cnpj)
            ->assertJsonPath('data.customer.company.state_registration', null)
            ->assertJsonPath('data.customer.company.state_registration_exempt', true);

        $company = Company::query()->where('cnpj', $cnpj)->firstOrFail();
        self::assertSame($company->id, Customer::query()->where('email', 'compras@grafica.com')->value('company_id'));
    }

    public function test_registers_a_company_with_state_registration(): void
    {
        $this->postJson('/api/v1/auth/register', $this->pj())
            ->assertCreated()
            ->assertJsonPath('data.customer.company.cnpj', '11222333000181')
            ->assertJsonPath('data.customer.company.state_registration', '123456789');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidRegistrations(): array
    {
        return [
            'invalid cpf' => [['cpf' => '111.111.111-11'], 'cpf'],
            'bad cpf dv' => [['cpf' => '529.982.247-26'], 'cpf'],
            'missing cpf for PF' => [['cpf' => null], 'cpf'],
            'single word name for PF' => [['name' => 'João'], 'name'],
            'html in name' => [['name' => '<b>João</b> Silva'], 'name'],
            'invalid email' => [['email' => 'not-an-email'], 'email'],
            'short phone' => [['phone' => '4799'], 'phone'],
            'weak password' => [['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'], 'password'],
            'password contains email' => [['password' => 'joao1234x', 'password_confirmation' => 'joao1234x'], 'password'],
            'password not confirmed' => [['password_confirmation' => 'outra1234'], 'password'],
            'terms not accepted' => [['accept_terms' => false], 'accept_terms'],
            'old terms version' => [['terms_version' => '2025-01'], 'terms_version'],
            'bad type' => [['type' => 'robot'], 'type'],
            'company data for PF' => [['company' => ['cnpj' => '11222333000181']], 'company'],
        ];
    }

    #[DataProvider('invalidRegistrations')]
    public function test_registration_validation(array $overrides, string $field): void
    {
        $this->postJson('/api/v1/auth/register', $this->pf($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
        self::assertSame(0, Customer::query()->count());
    }

    public function test_company_validation(): void
    {
        $this->postJson('/api/v1/auth/register', $this->pj(['cnpj' => '11.222.333/0001-80']))->assertJsonValidationErrors('company.cnpj');
        $this->postJson('/api/v1/auth/register', $this->pj(['state_registration' => null]))->assertJsonValidationErrors('company.state_registration');
        $this->postJson('/api/v1/auth/register', $this->pj(['state_registration_exempt' => true]))->assertJsonValidationErrors('company.state_registration');
        $this->postJson('/api/v1/auth/register', $this->pj(['legal_name' => null]))->assertJsonValidationErrors('company.legal_name');
        $this->postJson('/api/v1/auth/register', $this->pf(['type' => 'company', 'cpf' => null]))->assertJsonValidationErrors('company');
        self::assertSame(0, Company::query()->count());
    }

    public function test_duplicates_are_reported_per_field(): void
    {
        $this->customer(['email' => 'joao@example.com', 'cpf' => '52998224725']);
        Company::factory()->create(['cnpj' => '11222333000181']);

        $this->postJson('/api/v1/auth/register', $this->pf())
            ->assertJsonValidationErrors(['email' => 'Já existe uma conta com este e-mail.', 'cpf']);
        $this->postJson('/api/v1/auth/register', $this->pj())->assertJsonValidationErrors('company.cnpj');
    }

    /** @return array<string, array{0: string}> */
    public static function prohibitedFields(): array
    {
        return array_combine(
            $f = ['price_list_id', 'company_id', 'is_active', 'email_verified_at', 'roles', 'customer_id', 'status', 'id', 'uuid'],
            array_map(static fn ($x) => [$x], $f),
        );
    }

    #[DataProvider('prohibitedFields')]
    public function test_mass_assignment_fields_are_rejected(string $field): void
    {
        $this->postJson('/api/v1/auth/register', $this->pf([$field => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => "O campo {$field} não é permitido."]);
        self::assertSame(0, Customer::query()->count());
    }

    public function test_unknown_fields_are_ignored(): void
    {
        $this->postJson('/api/v1/auth/register', $this->pf(['is_admin' => true]))->assertCreated();
    }

    public function test_logged_in_customer_cannot_register(): void
    {
        $this->actingAsCustomer();
        $this->assertApiError($this->postJson('/api/v1/auth/register', $this->pf()), 403, 'forbidden');
    }

    public function test_login_regenerates_the_session_and_returns_the_customer(): void
    {
        $customer = $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);

        $this->getJson('/api/v1/settings/public')->assertOk();
        $guestSession = $this->sessionId();
        $this->useSession($guestSession)->getJson('/api/v1/settings/public');
        self::assertSame($guestSession, $this->sessionId(), 'cookie keeps the same session');

        $this->useSession($guestSession)->postJson('/api/v1/auth/login', ['email' => ' ANA@example.com ', 'password' => 'segredo123'])
            ->assertOk()
            ->assertJsonPath('data.customer.uuid', $customer->uuid)
            ->assertJsonPath('data.cart_merge', null);

        self::assertNotSame($guestSession, $this->sessionId(), 'session fixation: id must change on login');
        self::assertNotNull($customer->refresh()->last_login_at);

        $authenticated = $this->sessionId();
        $this->useSession($authenticated)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'ana@example.com');
        $this->useSession($guestSession)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_invalid_credentials_use_a_neutral_message(): void
    {
        $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);

        $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'errada123'])
            ->assertUnprocessable()->json('errors');
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'errada123'])
            ->assertUnprocessable()->json('errors');

        self::assertSame(['email' => ['E-mail ou senha inválidos.']], $wrong);
        self::assertSame($wrong, $unknown);
        $this->assertGuest('customer');
    }

    public function test_blocked_account_cannot_login(): void
    {
        $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123', 'is_active' => false]);

        $this->assertApiError($this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123']), 403, 'account_disabled');
        $this->assertGuest('customer');
    }

    public function test_login_is_rate_limited_per_ip_and_email(): void
    {
        $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'errada'])->assertUnprocessable();
        }
        $this->assertApiError($this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123']), 429, 'too_many_requests')
            ->assertHeader('Retry-After');
        $this->assertGuest('customer');

        // another e-mail from the same IP is still allowed (limit is IP+e-mail)
        $this->postJson('/api/v1/auth/login', ['email' => 'other@example.com', 'password' => 'x'])->assertUnprocessable();
    }

    public function test_login_merges_the_guest_cart_through_the_contract(): void
    {
        $customer = $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);
        $token = (string) Str::uuid();
        $this->app->instance(GuestCartMerger::class, new class implements GuestCartMerger
        {
            public array $calls = [];

            public function merge(?string $guestCartToken, int $customerId): ?CartMergeReport
            {
                $this->calls[] = [$guestCartToken, $customerId];

                return new CartMergeReport(true, 2, 1);
            }
        });

        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123'], ['X-Cart-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.cart_merge.merged', true)
            ->assertJsonPath('data.cart_merge.lines_added', 2)
            ->assertJsonPath('data.cart_merge.lines_combined', 1)
            ->assertJsonPath('data.cart_merge.adjustments', [])
            ->assertJsonPath('data.cart_merge.coupon', null);

        self::assertSame([[$token, $customer->id]], $this->app->make(GuestCartMerger::class)->calls);
    }

    public function test_invalid_cart_token_is_not_merged(): void
    {
        $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123'], ['X-Cart-Token' => 'nope'])
            ->assertOk()->assertJsonPath('data.cart_merge', null);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123'])->assertOk();
        $session = $this->sessionId();

        $this->useSession($session)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->useSession($session)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertApiError($this->useSession($session)->postJson('/api/v1/auth/logout'), 401, 'unauthenticated');
    }

    public function test_blocked_customer_loses_existing_session(): void
    {
        $customer = $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123'])->assertOk();
        $session = $this->sessionId();

        $customer->forceFill(['is_active' => false])->save();
        $this->assertApiError($this->useSession($session)->getJson('/api/v1/me'), 401, 'unauthenticated');
    }

    public function test_forgot_password_is_neutral_and_reset_works_once(): void
    {
        Notification::fake();
        Event::fake([CustomerPasswordReset::class]);
        $customer = $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);

        $neutral = ['data' => ['message' => 'Se o e-mail existir, enviaremos instruções.']];
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk()->assertExactJson($neutral);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ANA@example.com'])->assertOk()->assertExactJson($neutral);

        $token = null;
        Notification::assertSentTo($customer, CustomerResetPasswordNotification::class, function ($n) use (&$token, $customer) {
            $token = $n->token;

            return str_contains($n->url($customer), '/redefinir-senha?token=');
        });

        $this->postJson('/api/v1/auth/reset-password', ['token' => 'bad', 'email' => 'ana@example.com', 'password' => 'novaSenha99', 'password_confirmation' => 'novaSenha99'])
            ->assertJsonValidationErrors(['token' => 'Este link expirou ou é inválido.']);

        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'ana@example.com', 'password' => 'novaSenha99', 'password_confirmation' => 'novaSenha99'])
            ->assertOk()->assertExactJson(['data' => ['message' => 'Senha alterada.']]);
        $this->assertGuest('customer');
        Event::assertDispatched(CustomerPasswordReset::class);

        // single use
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'ana@example.com', 'password' => 'outraSenha99', 'password_confirmation' => 'outraSenha99'])
            ->assertJsonValidationErrors('token');

        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'novaSenha99'])->assertOk();
    }

    public function test_password_reset_invalidates_other_sessions(): void
    {
        Notification::fake();
        $customer = $this->customer(['email' => 'ana@example.com', 'password' => 'segredo123']);
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'segredo123'])->assertOk();
        $old = $this->sessionId();
        $this->useSession($old)->getJson('/api/v1/me')->assertOk();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ana@example.com']);
        $token = null;
        Notification::assertSentTo($customer, CustomerResetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->useSession('fresh-browser-session-id-0000000000000')
            ->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'ana@example.com', 'password' => 'novaSenha99', 'password_confirmation' => 'novaSenha99'])
            ->assertOk();

        $this->useSession($old)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_email_verification(): void
    {
        $customer = $this->customer(['email_verified_at' => null]);
        $params = EmailVerificationSignature::paramsFor($customer);

        $this->postJson('/api/v1/auth/email/verify', [...$params, 'signature' => str_repeat('a', 64)])->assertJsonValidationErrors('signature');
        $this->postJson('/api/v1/auth/email/verify', $params)->assertOk()->assertExactJson(['data' => ['verified' => true]]);
        self::assertNotNull($customer->refresh()->email_verified_at);

        $this->travel(4)->days();
        $this->postJson('/api/v1/auth/email/verify', $params)->assertJsonValidationErrors('signature');
    }

    public function test_resend_verification_requires_login(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();

        $customer = $this->actingAsCustomer($this->customer(['email_verified_at' => null]));
        $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        Notification::assertSentTo($customer, CustomerVerifyEmailNotification::class);
    }

    public function test_terms_version_follows_the_setting(): void
    {
        app(SettingsRepository::class)->set(SettingKey::LegalTermsVersion, '2026-09');

        $this->postJson('/api/v1/auth/register', $this->pf())->assertJsonValidationErrors('terms_version');
        $this->postJson('/api/v1/auth/register', $this->pf(['terms_version' => '2026-09']))->assertCreated()
            ->assertJsonPath('data.customer.terms.current_version', '2026-09');
    }
}
