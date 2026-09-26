<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Shared helpers for the Catalog / Pricing / Inventory / Seo feature tests (agent B-B). */
abstract class CatalogTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();
        foreach (AdminPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'admin');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function seedDemo(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        Cache::flush();
    }

    protected function actingAsAdminWith(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        if ($permissions !== []) {
            $admin->givePermissionTo($permissions);
        }
        $this->actingAs($admin, 'admin');

        return $admin;
    }
}
