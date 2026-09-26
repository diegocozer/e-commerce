<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdminUser */
final class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AdminUser $a */
        $a = $this->resource;

        return [
            'id' => $a->id,
            'name' => $a->name,
            'email' => $a->email,
            'is_active' => $a->is_active,
            'roles' => $a->getRoleNames()->values()->all(),
            'last_login_at' => Iso::dt($a->last_login_at),
            'created_at' => Iso::dt($a->created_at),
            'deleted_at' => Iso::dt($a->deleted_at),
        ];
    }
}
