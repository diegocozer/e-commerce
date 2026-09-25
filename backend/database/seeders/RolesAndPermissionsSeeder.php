<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** ADR-023/027/028 — permissions of API.md §6.1 and system roles of §6.2, guard `admin`. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(PermissionRegistrar $registrar): void
    {
        $registrar->forgetCachedPermissions();

        foreach (AdminPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, AdminPermission::GUARD);
        }

        foreach (AdminRole::cases() as $role) {
            Role::findOrCreate($role->value, AdminPermission::GUARD)
                ->syncPermissions(array_map(static fn (AdminPermission $p): string => $p->value, $role->permissions()));
        }

        $registrar->forgetCachedPermissions();
    }
}
