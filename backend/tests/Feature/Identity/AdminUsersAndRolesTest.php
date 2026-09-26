<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\AdminPermission as P;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Notifications\AdminResetPasswordNotification;
use App\Modules\Identity\Support\Guardrails;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Identity\Support\ApiTestCase;

final class AdminUsersAndRolesTest extends ApiTestCase
{
    public function test_manager_cannot_manage_users_or_roles(): void
    {
        $this->actingAsAdmin([], AdminRole::Manager);
        foreach ([['get', '/api/v1/admin/users'], ['post', '/api/v1/admin/users'], ['get', '/api/v1/admin/roles'],
            ['post', '/api/v1/admin/roles'], ['get', '/api/v1/admin/permissions']] as [$m, $url]) {
            $this->assertApiError($this->json($m, $url), 403, 'forbidden');
        }
    }

    public function test_lists_and_filters_users(): void
    {
        $this->superAdmin();
        $this->admin([], AdminRole::Seller, ['name' => 'Vera Vendedora', 'email' => 'vera@example.com']);
        $this->admin([], AdminRole::Finance, ['name' => 'Fábio Financeiro', 'is_active' => false]);
        $gone = $this->admin([], AdminRole::Warehouse, ['name' => 'Excluído']);
        $gone->delete();

        $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.per_page', 25);
        $this->getJson('/api/v1/admin/users?role=seller')->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'vera@example.com');
        $this->getJson('/api/v1/admin/users?q=vera')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/users?is_active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Fábio Financeiro');
        $this->getJson('/api/v1/admin/users?include_deleted=1')->assertJsonCount(4, 'data');
        $this->getJson("/api/v1/admin/users/{$gone->id}")->assertOk()->assertJsonPath('data.deleted_at', fn ($v) => $v !== null);
    }

    public function test_creates_user_with_invitation(): void
    {
        Notification::fake();
        $actor = $this->superAdmin();

        $id = $this->postJson('/api/v1/admin/users', ['name' => 'Nova Pessoa', 'email' => 'Nova@Example.com', 'roles' => ['seller'], 'password' => 'x', 'is_active' => false])
            ->assertCreated()
            ->assertJsonPath('data.email', 'nova@example.com')
            ->assertJsonPath('data.roles', ['seller'])
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $user = AdminUser::query()->findOrFail($id);
        Notification::assertSentTo($user, AdminResetPasswordNotification::class, fn ($n) => $n->invitation);
        self::assertTrue(AuditLog::query()->where('action', 'admin_user.created')->where('actor_id', $actor->id)->exists());

        $this->postJson('/api/v1/admin/users', ['name' => 'Outra', 'email' => 'nova@example.com', 'roles' => ['seller']])->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/admin/users', ['name' => 'Outra', 'email' => 'o@example.com', 'roles' => []])->assertJsonValidationErrors('roles');
        $this->postJson('/api/v1/admin/users', ['name' => 'Outra', 'email' => 'o@example.com', 'roles' => ['ghost']])->assertJsonValidationErrors('roles.0');
    }

    public function test_invitation_link_is_valid_for_72_hours(): void
    {
        Notification::fake();
        $this->superAdmin();
        $id = $this->postJson('/api/v1/admin/users', ['name' => 'Nova Pessoa', 'email' => 'nova@example.com', 'roles' => ['seller']])->json('data.id');
        $user = AdminUser::query()->findOrFail($id);
        $token = null;
        Notification::assertSentTo($user, AdminResetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->travel(48)->hours();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/admin/auth/reset-password', ['token' => $token, 'email' => 'nova@example.com', 'password' => 'Nova#Senha2026x', 'password_confirmation' => 'Nova#Senha2026x'])
            ->assertOk();
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'nova@example.com', 'password' => 'Nova#Senha2026x'])->assertOk();
    }

    public function test_updates_user_and_syncs_roles(): void
    {
        $actor = $this->superAdmin();
        $user = $this->admin([], AdminRole::Seller);

        $this->patchJson("/api/v1/admin/users/{$user->id}", ['name' => 'Nome Novo', 'roles' => ['warehouse', 'finance']])->assertOk()
            ->assertJsonPath('data.name', 'Nome Novo');
        self::assertEqualsCanonicalizing(['warehouse', 'finance'], $user->refresh()->getRoleNames()->all());
        $log = AuditLog::query()->where('action', 'admin_user.updated')->where('auditable_id', $user->id)->firstOrFail();
        self::assertSame(['seller'], $log->old_values['roles']);
        self::assertSame($actor->id, $log->actor_id);
    }

    public function test_anti_escalation(): void
    {
        $super = $this->admin([], AdminRole::SuperAdmin);
        $target = $this->admin([], AdminRole::Seller);
        $actor = $this->actingAsAdmin([P::AdminUsersManage, P::OrdersView]);

        // own roles
        $this->assertApiError($this->patchJson("/api/v1/admin/users/{$actor->id}", ['roles' => ['manager']]), 403, 'forbidden');
        // grant super-admin
        $this->assertApiError($this->patchJson("/api/v1/admin/users/{$target->id}", ['roles' => ['super-admin']]), 403, 'forbidden');
        $this->assertApiError($this->postJson('/api/v1/admin/users', ['name' => 'Xx Yy', 'email' => 'x@example.com', 'roles' => ['super-admin']]), 403, 'forbidden');
        // role with permissions the actor lacks
        $this->assertApiError($this->patchJson("/api/v1/admin/users/{$target->id}", ['roles' => ['manager']]), 403, 'forbidden');
        // touching a super-admin
        $this->assertApiError($this->postJson("/api/v1/admin/users/{$super->id}/deactivate"), 403, 'forbidden');
        $this->assertApiError($this->deleteJson("/api/v1/admin/users/{$super->id}"), 403, 'forbidden');
        // self deactivate/delete
        $this->assertApiError($this->postJson("/api/v1/admin/users/{$actor->id}/deactivate"), 403, 'forbidden');
        $this->assertApiError($this->deleteJson("/api/v1/admin/users/{$actor->id}"), 403, 'forbidden');
        // creating a role with permissions the actor lacks
        $this->assertApiError($this->postJson('/api/v1/admin/roles', ['name' => 'escalada', 'permissions' => ['settings.manage']]), 403, 'forbidden');

        self::assertSame(['seller'], $target->refresh()->getRoleNames()->all());
        self::assertTrue($super->refresh()->is_active);
        self::assertSame(0, AdminUser::query()->where('email', 'x@example.com')->count());
        self::assertFalse(Role::query()->where('name', 'escalada')->exists());
    }

    public function test_last_super_admin_is_protected(): void
    {
        $actor = $this->superAdmin();
        $other = $this->admin([], AdminRole::SuperAdmin);

        $this->postJson("/api/v1/admin/users/{$other->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        // actor is now the last active super-admin: other super-admin cannot remove the role from them
        $this->assertApiError($this->postJson("/api/v1/admin/users/{$actor->id}/deactivate"), 403, 'forbidden');

        $this->postJson("/api/v1/admin/users/{$other->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->patchJson("/api/v1/admin/users/{$other->id}", ['roles' => ['manager']])->assertOk();

        // a user manager (not super) cannot deactivate the last super-admin either
        $this->app['session.store']->flush();
        $this->actingAsAdmin([P::AdminUsersManage]);
        $this->assertApiError($this->postJson("/api/v1/admin/users/{$actor->id}/deactivate"), 403, 'forbidden');
        self::assertTrue($actor->refresh()->is_active);
    }

    public function test_guardrail_keeps_one_active_super_admin(): void
    {
        $only = $this->admin([], AdminRole::SuperAdmin);
        $this->admin([], AdminRole::SuperAdmin, ['is_active' => false]);

        try {
            Guardrails::assertKeepsASuperAdmin($only);
            self::fail('Expected a validation error.');
        } catch (ValidationException $e) {
            self::assertSame(['roles' => ['É preciso manter ao menos um Super Admin ativo.']], $e->errors());
        }

        $this->admin([], AdminRole::SuperAdmin);
        Guardrails::assertKeepsASuperAdmin($only); // another active one exists now
        $this->addToAssertionCount(1);
    }

    public function test_deactivate_activate_delete_and_reset(): void
    {
        Notification::fake();
        $actor = $this->superAdmin();
        $user = $this->admin([], AdminRole::Seller, ['last_login_at' => now()]);

        $this->postJson("/api/v1/admin/users/{$user->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("/api/v1/admin/users/{$user->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->postJson("/api/v1/admin/users/{$user->id}/password-reset")->assertStatus(202);
        Notification::assertSentTo($user, AdminResetPasswordNotification::class, fn ($n) => ! $n->invitation);
        $this->deleteJson("/api/v1/admin/users/{$user->id}")->assertNoContent();
        self::assertSoftDeleted('admin_users', ['id' => $user->id]);
        $this->deleteJson("/api/v1/admin/users/{$user->id}")->assertNotFound();

        foreach (['admin_user.deactivated', 'admin_user.activated', 'admin_user.password_reset_sent', 'admin_user.deleted'] as $action) {
            self::assertTrue(AuditLog::query()->where('action', $action)->where('actor_id', $actor->id)->exists(), $action);
        }
    }

    public function test_roles_crud(): void
    {
        $this->superAdmin();

        $this->getJson('/api/v1/admin/roles')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.name', 'super-admin')->assertJsonPath('data.0.is_system', true)
            ->assertJsonPath('data.1.label', 'Gerente');

        $id = $this->postJson('/api/v1/admin/roles', ['name' => 'atendimento', 'permissions' => ['customers.view', 'orders.view']])
            ->assertCreated()
            ->assertJsonPath('data.label', 'atendimento')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.permissions', ['customers.view', 'orders.view'])
            ->assertJsonPath('data.users_count', 0)
            ->json('data.id');

        $this->postJson('/api/v1/admin/roles', ['name' => 'Com Espaço', 'permissions' => []])->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/admin/roles', ['name' => 'atendimento', 'permissions' => []])->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/admin/roles', ['name' => 'outro', 'permissions' => ['nope.x']])->assertJsonValidationErrors('permissions.0');

        $this->patchJson("/api/v1/admin/roles/{$id}", ['name' => 'atendimento-2', 'permissions' => ['customers.view']])->assertOk()
            ->assertJsonPath('data.name', 'atendimento-2')->assertJsonPath('data.permissions', ['customers.view']);
        $this->getJson("/api/v1/admin/roles/{$id}")->assertOk()->assertJsonPath('data.name', 'atendimento-2');

        $user = $this->admin();
        $user->assignRole('atendimento-2');
        $this->assertApiError($this->deleteJson("/api/v1/admin/roles/{$id}"), 409, 'resource_in_use')
            ->assertJsonPath('blockers.0.id', $user->id);
        $user->removeRole('atendimento-2');
        $this->deleteJson("/api/v1/admin/roles/{$id}")->assertNoContent();

        foreach (['role.created', 'role.updated', 'role.deleted'] as $action) {
            self::assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }
    }

    public function test_system_roles_are_protected(): void
    {
        $this->superAdmin();
        $super = Role::findByName('super-admin', 'admin');
        $manager = Role::findByName('manager', 'admin');

        $this->assertApiError($this->patchJson("/api/v1/admin/roles/{$super->id}", ['permissions' => ['orders.view']]), 403, 'forbidden');
        $this->assertApiError($this->patchJson("/api/v1/admin/roles/{$manager->id}", ['name' => 'gerente']), 403, 'forbidden');
        $this->assertApiError($this->deleteJson("/api/v1/admin/roles/{$manager->id}"), 403, 'forbidden');
        $this->patchJson("/api/v1/admin/roles/{$manager->id}", ['permissions' => ['orders.view']])->assertOk()->assertJsonPath('data.permissions', ['orders.view']);
    }

    public function test_permissions_list(): void
    {
        $this->superAdmin();
        $this->getJson('/api/v1/admin/permissions')->assertOk()
            ->assertJsonCount(count(P::cases()), 'data')
            ->assertJsonFragment(['name' => 'audit_logs.view', 'label' => 'Ver auditoria', 'group' => 'audit']);
    }
}
