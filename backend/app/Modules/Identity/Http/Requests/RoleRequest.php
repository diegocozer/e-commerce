<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\AdminPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** POST /admin/roles and PATCH /admin/roles/{id}. */
final class RoleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $role = $this->route('role');
        $ignore = $role instanceof Role ? $role->id : (is_numeric($role) ? (int) $role : null);

        return [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'regex:/^[a-z0-9-]{3,50}$/',
                Rule::unique('roles', 'name')->where('guard_name', AdminPermission::GUARD)->ignore($ignore)],
            'permissions' => [$create ? 'present' : 'sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(AdminPermission::values())],
        ];
    }
}
