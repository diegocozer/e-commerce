<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Default super admin (local/testing only — DATABASE.md §7.2). */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The default admin user must not be seeded in production.');
        }

        $admin = AdminUser::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Administrador', 'password' => env('SEED_ADMIN_PASSWORD', 'password'), 'is_active' => true],
        );

        $admin->syncRoles([AdminRole::SuperAdmin->value]);
    }
}
