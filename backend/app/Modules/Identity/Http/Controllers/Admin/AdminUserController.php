<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Actions\ManageAdminUsers;
use App\Modules\Identity\Http\Requests\AdminUserIndexRequest;
use App\Modules\Identity\Http\Requests\AdminUserRequest;
use App\Modules\Identity\Http\Resources\AdminUserResource;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class AdminUserController
{
    public function index(AdminUserIndexRequest $request): AnonymousResourceCollection
    {
        $query = AdminUser::query()->with('roles');

        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }
        if ($request->filled('q')) {
            $term = '%'.addcslashes((string) $request->validated('q'), '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term));
        }
        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->validated('role')));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = (string) ($request->validated('sort') ?? 'name');
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');

        return AdminUserResource::collection($query->paginate((int) ($request->validated('per_page') ?? 25))->withQueryString());
    }

    public function show(int $user): AdminUserResource
    {
        return new AdminUserResource(AdminUser::withTrashed()->with('roles')->findOrFail($user));
    }

    public function store(AdminUserRequest $request, ManageAdminUsers $users): JsonResponse
    {
        $admin = $users->create(self::actor($request), $request->validated());

        return (new AdminUserResource($admin))->response()->setStatusCode(201);
    }

    public function update(AdminUserRequest $request, int $user, ManageAdminUsers $users): AdminUserResource
    {
        return new AdminUserResource($users->update(self::actor($request), AdminUser::query()->findOrFail($user), $request->validated()));
    }

    public function destroy(Request $request, int $user, ManageAdminUsers $users): Response
    {
        $users->delete(self::actor($request), AdminUser::query()->findOrFail($user));

        return response()->noContent();
    }

    public static function actor(Request $request): AdminUser
    {
        /** @var AdminUser */
        return $request->user('admin');
    }
}
