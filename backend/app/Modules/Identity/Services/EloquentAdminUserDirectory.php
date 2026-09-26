<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\AdminUserDirectory;
use App\Modules\Identity\DTOs\AdminUserData;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;

final class EloquentAdminUserDirectory implements AdminUserDirectory
{
    public function find(int $adminUserId): ?AdminUserData
    {
        $admin = AdminUser::query()->with('roles')->find($adminUserId);

        return $admin === null ? null : new AdminUserData(
            $admin->id, $admin->name, $admin->email, $admin->is_active, $admin->roles->pluck('name')->values()->all(),
        );
    }

    public function emailsWithPermission(AdminPermission $permission): array
    {
        return AdminUser::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->permission($permission->value)->orWhereHas('roles', fn ($r) => $r->where('name', AdminRole::SuperAdmin->value)))
            ->orderBy('id')
            ->pluck('email')
            ->unique()
            ->values()
            ->all();
    }
}
