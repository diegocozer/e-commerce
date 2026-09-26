<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\AdminSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API.md §2.12 AdminMe. */
final class AdminMeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AdminUser $admin */
        $admin = $this->resource;
        $super = $admin->isSuperAdmin();
        $permissions = $super
            ? AdminPermission::values()
            : array_values(array_intersect(AdminPermission::values(), $admin->getAllPermissions()->pluck('name')->all()));

        return [
            'user' => (new AdminUserResource($admin))->resolve($request),
            'permissions' => $permissions,
            'is_super_admin' => $super,
            'session' => [
                'idle_timeout_seconds' => (int) config('auth.admin_session.idle_timeout_minutes', 30) * 60,
                'absolute_expires_at' => CarbonImmutable::createFromTimestamp(AdminSession::absoluteExpiresAt($request))->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }
}
