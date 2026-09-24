<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\AdminRole;
use Database\Factories\Identity\AdminUserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Panel user (guard `admin`, DATABASE.md §3.9.1). Roles/permissions via
 * spatie/laravel-permission with guard `admin`; `super-admin` passes every
 * Gate check (Gate::before in IdentityServiceProvider).
 * `is_active`, `last_login_*` are set explicitly by Identity actions.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_active
 */
class AdminUser extends Authenticatable
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'admin_users';

    protected string $guard_name = 'admin';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    /** E-mails are stored lower-case (DB-02, CHECK email = lower(email)). */
    protected function email(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => mb_strtolower(trim($value)));
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(AdminRole::SuperAdmin->value);
    }

    protected static function newFactory(): AdminUserFactory
    {
        return AdminUserFactory::new();
    }
}
