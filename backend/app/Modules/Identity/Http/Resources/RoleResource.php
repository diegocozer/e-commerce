<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Enums\AdminRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/** API.md §2.12 Role. */
final class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;
        $system = AdminRole::tryFrom($role->name);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $system?->label() ?? $role->name,
            'is_system' => $system !== null,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            'users_count' => (int) ($role->users_count ?? $role->users()->count()),
        ];
    }
}
