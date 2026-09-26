<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Actions\ManageAdminUsers;
use App\Modules\Identity\Http\Resources\AdminUserResource;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Http\Request;

final class AdminUserStatusController
{
    public function activate(Request $request, int $user, ManageAdminUsers $users): AdminUserResource
    {
        return new AdminUserResource($users->setActive(AdminUserController::actor($request), AdminUser::query()->findOrFail($user), true));
    }

    public function deactivate(Request $request, int $user, ManageAdminUsers $users): AdminUserResource
    {
        return new AdminUserResource($users->setActive(AdminUserController::actor($request), AdminUser::query()->findOrFail($user), false));
    }
}
