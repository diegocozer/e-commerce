<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AdminUser> */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /** Requires the roles to exist (RolesAndPermissionsSeeder). */
    public function withRole(AdminRole $role): static
    {
        return $this->afterCreating(fn (AdminUser $admin) => $admin->assignRole($role->value));
    }
}
