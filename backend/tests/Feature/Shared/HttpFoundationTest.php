<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\AdminUser;
use App\Shared\Exceptions\DomainException;
use App\Shared\Http\Middleware\EnsureAdminSessionIsFresh;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HttpFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1')->group(function (): void {
            Route::get('_test/domain', static fn () => throw new class('Estoque insuficiente.') extends DomainException
            {
                protected string $errorCode = 'insufficient_stock';
            });
            Route::get('_test/domain-details', static fn () => throw new class('Preço mudou.', 'price_changed', 409, ['summary' => ['total_cents' => 100]]) extends DomainException {});
            Route::post('_test/validate', static fn (Request $r) => $r->validate(['name' => 'required']));
            Route::get('_test/boom', static fn () => throw new \RuntimeException('secret internals'));
            Route::get('me/_test', static fn () => ['ok' => true])->middleware('auth:customer');
        });
        Route::middleware(ModuleServiceProvider::ROUTE_GROUPS['admin.php']['middleware'])
            ->prefix('api/v1/admin')->get('_test', static fn () => ['ok' => true]);
    }

    public function test_health_endpoints(): void
    {
        $this->getJson('/api/health/live')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'cache' => 'ok']]);
    }

    public function test_request_id_is_generated_or_propagated(): void
    {
        $generated = $this->getJson('/api/health/live')->headers->get('X-Request-Id');
        self::assertTrue(Str::isUlid((string) $generated));

        $uuid = (string) Str::uuid();
        $this->getJson('/api/health/live', ['X-Request-Id' => $uuid])->assertHeader('X-Request-Id', $uuid);

        $replaced = $this->getJson('/api/health/live', ['X-Request-Id' => '<script>'])->headers->get('X-Request-Id');
        self::assertNotSame('<script>', $replaced);
    }

    public function test_security_headers_on_api_responses(): void
    {
        $this->getJson('/api/health/live')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_error_bodies_follow_adr_020(): void
    {
        $this->get('/api/v1/does-not-exist')->assertNotFound()->assertExactJson(['message' => 'Recurso não encontrado.', 'code' => 'not_found']);
        $this->get('/api/v1/_test/domain')->assertStatus(409)->assertExactJson(['message' => 'Estoque insuficiente.', 'code' => 'insufficient_stock']);
        $this->get('/api/v1/_test/domain-details')->assertStatus(409)
            ->assertExactJson(['message' => 'Preço mudou.', 'code' => 'price_changed', 'summary' => ['total_cents' => 100]]);
        $this->getJson('/api/v1/me/_test')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);
        $this->postJson('/api/v1/_test/validate')->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['name']])->assertJsonMissingPath('code');
    }

    public function test_server_errors_hide_internals_in_production_mode(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_test/boom')->assertStatus(500)->assertJson(['code' => 'server_error']);
        self::assertStringNotContainsString('secret internals', (string) $response->getContent());
    }

    public function test_guards_are_isolated(): void
    {
        $customer = Customer::factory()->create();
        $admin = AdminUser::factory()->create();

        $this->actingAs($customer, 'customer')->getJson('/api/v1/me/_test')->assertOk();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/admin/_test')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/admin/_test')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_admin_session_expires_after_inactivity(): void
    {
        $admin = AdminUser::factory()->create();

        // A stateful origin makes Sanctum start the session (as the admin SPA does).
        $this->actingAs($admin, 'admin')
            ->withHeader('Referer', 'http://localhost/admin/')
            ->withSession([EnsureAdminSessionIsFresh::LAST_ACTIVITY_AT => now()->subMinutes(31)->getTimestamp()])
            ->getJson('/api/v1/admin/_test')
            ->assertUnauthorized()
            ->assertJson(['code' => 'admin_session_expired']);
    }

    public function test_rate_limiters_are_registered(): void
    {
        foreach (['api', 'login', 'register', 'password-reset', 'shipping-quote', 'shipping-estimate', 'checkout', 'webhooks', 'customer', 'admin', 'health', 'seo'] as $name) {
            self::assertNotNull(RateLimiter::limiter($name), $name);
        }
    }
}
