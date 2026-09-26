<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Actions\ManageAdminUsers;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminUserPasswordResetController
{
    public function store(Request $request, int $user, ManageAdminUsers $users): JsonResponse
    {
        $users->sendPasswordReset(AdminUserController::actor($request), AdminUser::query()->findOrFail($user));

        return new JsonResponse(['data' => ['message' => 'Link de redefinição enviado.']], 202);
    }
}
