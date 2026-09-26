<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Exceptions\Forbidden;
use App\Modules\Identity\Exceptions\ResourceInUse;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\Guardrails;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Roles CRUD (API.md §3.G.12): super-admin immutable, system role names immutable, permissions synced. */
final class ManageRoles
{
    public function __construct(private readonly AuditLogger $audit, private readonly PermissionRegistrar $registrar) {}

    public static function isSystem(Role $role): bool
    {
        return AdminRole::tryFrom($role->name) !== null;
    }

    /** @param  array{name:string, permissions:list<string>}  $data */
    public function create(AdminUser $actor, array $data): Role
    {
        Guardrails::assertHoldsPermissions($actor, $data['permissions']);

        return DB::transaction(function () use ($actor, $data): Role {
            /** @var Role $role */
            $role = Role::query()->create(['name' => $data['name'], 'guard_name' => AdminPermission::GUARD]);
            $role->syncPermissions($data['permissions']);
            $this->audit->record(new AuditEntry(ActorRef::admin($actor->id), 'role.created', 'role', $role->id, null, [
                'name' => $role->name, 'permissions' => $data['permissions'],
            ]));
            $this->registrar->forgetCachedPermissions();

            return $role;
        });
    }

    /** @param  array{name?:string, permissions?:list<string>}  $data */
    public function update(AdminUser $actor, Role $role, array $data): Role
    {
        if ($role->name === AdminRole::SuperAdmin->value) {
            throw new Forbidden('O papel Super Admin não pode ser editado.');
        }
        if (isset($data['name']) && $data['name'] !== $role->name && self::isSystem($role)) {
            throw new Forbidden('O nome de um papel do sistema não pode ser alterado.');
        }
        if (! $actor->isSuperAdmin() && $role->users()->whereKey($actor->id)->exists()) {
            throw new Forbidden('Você não pode alterar um papel que você possui.');
        }

        $oldPermissions = $role->permissions->pluck('name')->sort()->values()->all();
        if (isset($data['permissions'])) {
            Guardrails::assertHoldsPermissions($actor, array_values(array_unique([
                ...array_diff($data['permissions'], $oldPermissions),
                ...array_diff($oldPermissions, $data['permissions']),
            ])));
        }

        return DB::transaction(function () use ($actor, $role, $data, $oldPermissions): Role {
            $old = ['name' => $role->name, 'permissions' => $oldPermissions];
            if (isset($data['name'])) {
                $role->name = $data['name'];
                $role->save();
            }
            if (isset($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }
            $new = ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')->sort()->values()->all()];
            $entry = AuditEntry::diff(ActorRef::admin($actor->id), 'role.updated', 'role', $role->id, $old, $new);
            if ($entry->newValues !== null) {
                $this->audit->record($entry);
            }
            $this->registrar->forgetCachedPermissions();

            return $role->refresh();
        });
    }

    public function delete(AdminUser $actor, Role $role): void
    {
        if (self::isSystem($role)) {
            throw new Forbidden('Papéis do sistema não podem ser excluídos.');
        }
        $users = $role->users()->limit(20)->get(['admin_users.id', 'admin_users.name']);
        if ($users->isNotEmpty()) {
            throw new ResourceInUse('O papel possui usuários.', details: [
                'blockers' => $users->map(fn ($u) => ['type' => 'admin_user', 'id' => $u->id, 'label' => $u->name])->values()->all(),
            ]);
        }

        DB::transaction(function () use ($actor, $role): void {
            $this->audit->record(new AuditEntry(ActorRef::admin($actor->id), 'role.deleted', 'role', $role->id, ['name' => $role->name], null));
            $role->delete();
            $this->registrar->forgetCachedPermissions();
        });
    }
}
