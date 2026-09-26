<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Actions\ManageRoles;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Modules\Identity\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\Permission\Models\Role;

final class RoleController
{
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(
            Role::query()->where('guard_name', AdminPermission::GUARD)->with('permissions')->withCount('users')->orderBy('id')->get(),
        );
    }

    public function show(int $role): RoleResource
    {
        return new RoleResource(self::find($role)->loadCount('users'));
    }

    public function store(RoleRequest $request, ManageRoles $roles): JsonResponse
    {
        $role = $roles->create(AdminUserController::actor($request), $request->validated());

        return (new RoleResource($role->load('permissions')->loadCount('users')))->response()->setStatusCode(201);
    }

    public function update(RoleRequest $request, int $role, ManageRoles $roles): RoleResource
    {
        $updated = $roles->update(AdminUserController::actor($request), self::find($role), $request->validated());

        return new RoleResource($updated->load('permissions')->loadCount('users'));
    }

    public function destroy(Request $request, int $role, ManageRoles $roles): Response
    {
        $roles->delete(AdminUserController::actor($request), self::find($role));

        return response()->noContent();
    }

    private static function find(int $id): Role
    {
        /** @var Role */
        return Role::query()->where('guard_name', AdminPermission::GUARD)->with('permissions')->findOrFail($id);
    }
}
