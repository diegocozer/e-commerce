<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /admin/users and PATCH /admin/users/{id}. Unknown fields (password, is_active…) are ignored. */
final class AdminUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $req = $create ? 'required' : 'sometimes';
        $target = $this->route('user');
        $ignore = $target instanceof AdminUser ? $target->id : (is_numeric($target) ? (int) $target : null);

        return [
            'name' => [$req, 'string', 'min:3', 'max:150'],
            'email' => [$req, 'string', 'email:rfc,strict', 'max:255',
                Rule::unique('admin_users', 'email')->whereNull('deleted_at')->ignore($ignore)],
            'roles' => [$req, 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct',
                Rule::exists('roles', 'name')->where('guard_name', AdminPermission::GUARD)],
        ];
    }
}
