<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\AdminPermission as P;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Notifications\AdminResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Identity\Support\ApiTestCase;

final class AdminAuthTest extends ApiTestCase
{
    private const string PASSWORD = 'Senha#Forte2026';

    private function login(string $email = 'op@example.com', string $password = self::PASSWORD)
    {
        return $this->postJson('/api/v1/admin/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_login_returns_admin_me_and_regenerates_session(): void
    {
        $admin = $this->admin([P::OrdersView, P::DashboardView], null, ['email' => 'op@example.com', 'password' => self::PASSWORD]);
        $this->getJson('/api/v1/settings/public');
        $guest = $this->sessionId();

        $this->useSession($guest);
        $this->login(' OP@example.com ')->assertOk()
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.roles', ['test-role-'.$admin->id])
            ->assertJsonPath('data.permissions', ['dashboard.view', 'orders.view'])
            ->assertJsonPath('data.is_super_admin', false)
            ->assertJsonPath('data.session.idle_timeout_seconds', 1800)
            ->assertJsonStructure(['data' => ['session' => ['absolute_expires_at']]]);

        $session = $this->sessionId();
        self::assertNotSame($guest, $session);
        self::assertNotNull($admin->refresh()->last_login_at);
        self::assertTrue(AuditLog::query()->where('action', 'admin_user.login')->where('actor_id', $admin->id)->exists());

        $this->useSession($session)->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.user.email', 'op@example.com');
        $this->useSession($guest)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_super_admin_receives_every_permission(): void
    {
        $this->superAdmin();
        $this->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.is_super_admin', true)
            ->assertJsonCount(count(P::cases()), 'data.permissions');
    }

    public function test_invalid_credentials_are_neutral_and_audited(): void
    {
        $this->admin([], null, ['email' => 'op@example.com', 'password' => self::PASSWORD]);

        $a = $this->login('op@example.com', 'wrong')->assertUnprocessable()->json('errors');
        $b = $this->login('ghost@example.com', 'wrong')->assertUnprocessable()->json('errors');
        self::assertSame(['email' => ['E-mail ou senha inválidos.']], $a);
        self::assertSame($a, $b);
        self::assertSame(2, AuditLog::query()->where('action', 'admin_user.login_failed')->count());
        self::assertStringNotContainsString('op@example.com', (string) json_encode(AuditLog::query()->pluck('new_values')));
        $this->assertGuest('admin');
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $this->admin([], null, ['email' => 'op@example.com', 'password' => self::PASSWORD, 'is_active' => false]);
        $this->assertApiError($this->login(), 403, 'account_disabled');
    }

    public function test_login_rate_limit(): void
    {
        $this->admin([], null, ['email' => 'op@example.com', 'password' => self::PASSWORD]);
        for ($i = 0; $i < 5; $i++) {
            $this->login('op@example.com', 'wrong')->assertUnprocessable();
        }
        $this->assertApiError($this->login(), 429, 'too_many_requests');
    }

    public function test_idle_and_absolute_timeouts(): void
    {
        $this->admin([], null, ['email' => 'op@example.com', 'password' => self::PASSWORD]);
        $this->login()->assertOk();
        $session = $this->sessionId();

        $this->travel(29)->minutes();
        $this->useSession($session)->getJson('/api/v1/admin/me')->assertOk();
        $this->travel(31)->minutes();
        $this->assertApiError($this->useSession($session)->getJson('/api/v1/admin/me'), 401, 'admin_session_expired');
        $this->useSession($session)->getJson('/api/v1/admin/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->useSession('fresh-browser-000000000000000000000000000');
        $this->login()->assertOk();
        $session = $this->sessionId();
        for ($i = 0; $i < 16; $i++) { // 16 × 29 min = 7 h 44
            $this->travel(29)->minutes();
            $this->useSession($session)->getJson('/api/v1/admin/me')->assertOk();
        }
        $this->travel(29)->minutes(); // > 8 h since login
        $this->assertApiError($this->useSession($session)->getJson('/api/v1/admin/me'), 401, 'admin_session_expired');
    }

    public function test_logout(): void
    {
        $admin = $this->admin([], null, ['email' => 'op@example.com', 'password' => self::PASSWORD]);
        $this->login()->assertOk();
        $session = $this->sessionId();

        $this->useSession($session)->postJson('/api/v1/admin/auth/logout')->assertNoContent();
        $this->useSession($session)->getJson('/api/v1/admin/me')->assertUnauthorized();
        self::assertTrue(AuditLog::query()->where('action', 'admin_user.logout')->where('actor_id', $admin->id)->exists());
    }

    public function test_guards_are_isolated(): void
    {
        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_deactivated_admin_loses_the_session(): void
    {
        $admin = $this->admin([], null, ['email' => 'op@example.com', 'password' => self::PASSWORD]);
        $this->login()->assertOk();
        $session = $this->sessionId();

        $admin->forceFill(['is_active' => false])->save();
        $this->assertApiError($this->useSession($session)->getJson('/api/v1/admin/me'), 401, 'unauthenticated');
    }

    public function test_change_password_drops_other_sessions(): void
    {
        $admin = $this->admin([], null, ['email' => 'op@example.com', 'password' => self::PASSWORD]);
        $this->login()->assertOk();
        $other = $this->sessionId();
        $this->useSession($other)->getJson('/api/v1/admin/me')->assertOk();

        $this->useSession('second-browser-00000000000000000000000000');
        $this->login()->assertOk();
        $current = $this->sessionId();

        $this->useSession($current)->putJson('/api/v1/admin/me/password', ['current_password' => 'wrong', 'password' => 'Nova#Senha2026x', 'password_confirmation' => 'Nova#Senha2026x'])
            ->assertJsonValidationErrors('current_password');
        $this->useSession($current)->putJson('/api/v1/admin/me/password', ['current_password' => self::PASSWORD, 'password' => 'fraca123', 'password_confirmation' => 'fraca123'])
            ->assertJsonValidationErrors('password');
        $this->useSession($current)->putJson('/api/v1/admin/me/password', ['current_password' => self::PASSWORD, 'password' => 'Nova#Senha2026x', 'password_confirmation' => 'Nova#Senha2026x'])
            ->assertNoContent();
        $current = $this->sessionId();

        self::assertTrue(Hash::check('Nova#Senha2026x', $admin->refresh()->password));
        $this->useSession($current)->getJson('/api/v1/admin/me')->assertOk();
        $this->useSession($other)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_forgot_and_reset_password(): void
    {
        Notification::fake();
        $admin = $this->admin([], null, ['email' => 'op@example.com', 'password' => self::PASSWORD, 'last_login_at' => now()]);
        $this->login()->assertOk();
        $oldSession = $this->sessionId();
        $this->useSession($oldSession)->getJson('/api/v1/admin/me')->assertOk();

        $this->useSession('reset-browser-000000000000000000000000000');
        $neutral = ['data' => ['message' => 'Se o e-mail existir, enviaremos instruções.']];
        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk()->assertExactJson($neutral);
        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'op@example.com'])->assertOk()->assertExactJson($neutral);

        $token = null;
        Notification::assertSentTo($admin, AdminResetPasswordNotification::class, function ($n) use (&$token, $admin) {
            $token = $n->token;

            return str_contains($n->url($admin), '/admin/redefinir-senha?token=') && ! $n->invitation;
        });

        $this->postJson('/api/v1/admin/auth/reset-password', ['token' => $token, 'email' => 'op@example.com', 'password' => 'curta', 'password_confirmation' => 'curta'])
            ->assertJsonValidationErrors('password');
        $this->travel(31)->minutes();
        $this->postJson('/api/v1/admin/auth/reset-password', ['token' => $token, 'email' => 'op@example.com', 'password' => 'Nova#Senha2026x', 'password_confirmation' => 'Nova#Senha2026x'])
            ->assertJsonValidationErrors('token');
        $this->travelBack();

        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'op@example.com']);
        Notification::assertSentTo($admin, AdminResetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->postJson('/api/v1/admin/auth/reset-password', ['token' => $token, 'email' => 'op@example.com', 'password' => 'Nova#Senha2026x', 'password_confirmation' => 'Nova#Senha2026x'])
            ->assertOk()->assertExactJson(['data' => ['message' => 'Senha alterada.']]);

        $this->useSession($oldSession)->getJson('/api/v1/admin/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->login('op@example.com', 'Nova#Senha2026x')->assertOk();
    }

    public function test_seeded_roles_match_the_contract(): void
    {
        $manager = $this->admin([], AdminRole::Manager);
        $seller = $this->admin([], AdminRole::Seller);
        self::assertFalse($manager->hasPermissionTo('admin_users.manage', 'admin'));
        self::assertTrue($manager->hasPermissionTo('settings.manage', 'admin'));
        self::assertFalse($seller->hasPermissionTo('orders.fulfill', 'admin'));
        self::assertInstanceOf(AdminUser::class, $seller);
    }
}
