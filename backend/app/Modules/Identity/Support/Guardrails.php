<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Exceptions\Forbidden;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/** Anti-escalation rules (SECURITY.md §4.2 / API.md §3.G.12). */
final class Guardrails
{
    /** Non super-admins cannot modify/deactivate/delete a super-admin. */
    public static function assertCanManage(AdminUser $actor, AdminUser $target): void
    {
        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw new Forbidden('Somente um Super Admin pode alterar outro Super Admin.');
        }
    }

    public static function assertNotSelf(AdminUser $actor, AdminUser $target, string $message): void
    {
        if ($actor->is($target)) {
            throw new Forbidden($message);
        }
    }

    /**
     * Only super-admin grants super-admin; others may only hand out roles whose
     * permissions they hold themselves.
     *
     * @param  list<string>  $roleNames
     */
    public static function assertCanAssignRoles(AdminUser $actor, array $roleNames): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }
        if (in_array(AdminRole::SuperAdmin->value, $roleNames, true)) {
            throw new Forbidden('Somente um Super Admin pode conceder o papel Super Admin.');
        }
        $roles = Role::query()->where('guard_name', 'admin')->whereIn('name', $roleNames)->with('permissions')->get();
        self::assertHoldsPermissions($actor, $roles->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique()->values()->all());
    }

    /** @param  list<string>  $permissions */
    public static function assertHoldsPermissions(AdminUser $actor, array $permissions): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }
        foreach ($permissions as $permission) {
            if (! $actor->hasPermissionTo($permission, 'admin')) {
                throw new Forbidden('Você não pode conceder permissões que não possui.');
            }
        }
    }

    /** At least one active super-admin must remain (RN-ADM-003) → 422 errors.roles. */
    public static function assertKeepsASuperAdmin(AdminUser $target): void
    {
        if (! $target->is_active || ! $target->isSuperAdmin()) {
            return;
        }
        $others = AdminUser::query()->whereKeyNot($target->id)->where('is_active', true)->role(AdminRole::SuperAdmin->value, 'admin')->exists();
        if (! $others) {
            throw ValidationException::withMessages(['roles' => 'É preciso manter ao menos um Super Admin ativo.']);
        }
    }
}
