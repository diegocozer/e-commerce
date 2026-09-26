<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Enums\AdminPermission;
use Illuminate\Http\JsonResponse;

final class PermissionController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => array_map(static fn (AdminPermission $p): array => [
            'name' => $p->value,
            'label' => $p->label(),
            'group' => $p->group(),
        ], AdminPermission::cases())]);
    }
}
