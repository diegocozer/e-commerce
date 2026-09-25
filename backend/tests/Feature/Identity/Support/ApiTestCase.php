<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Support;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Shared\PostalCode\PostalCodeInfo;
use App\Shared\PostalCode\PostalCodeLookup;
use App\Shared\PostalCode\PostalCodeLookupException;
use App\Shared\PostalCode\PostalCodeNotFoundException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Base for the B-A feature tests (Identity, Customers, Settings, Audit):
 * stateful SPA requests (Referer of a Sanctum stateful domain), seeded roles,
 * a fake CEP lookup and helpers to act as customer/admin.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, PostalCodeInfo|string> cep => info | 'not_found' | 'fail' */
    public static array $ceps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders(['Referer' => 'http://localhost/', 'Accept' => 'application/json']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RateLimiter::clear('x');

        self::$ceps = [
            '89010000' => new PostalCodeInfo('89010000', null, 'Centro', 'Blumenau', 'SC', '4202404'),
            '89012000' => new PostalCodeInfo('89012000', 'Rua das Palmeiras', 'Victor Konder', 'Blumenau', 'SC', '4202404'),
            '01310100' => new PostalCodeInfo('01310100', 'Avenida Paulista', 'Bela Vista', 'São Paulo', 'SP', '3550308'),
            '99999999' => 'not_found',
            '11111111' => 'fail',
        ];
        $this->app->instance(PostalCodeLookup::class, new class implements PostalCodeLookup
        {
            public function lookup(string $postalCode): PostalCodeInfo
            {
                $hit = ApiTestCase::$ceps[$postalCode] ?? 'not_found';

                return match ($hit) {
                    'not_found' => throw new PostalCodeNotFoundException('not found'),
                    'fail' => throw new PostalCodeLookupException('down'),
                    default => $hit,
                };
            }
        });
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create($attributes);
    }

    protected function actingAsCustomer(?Customer $customer = null): Customer
    {
        $customer ??= $this->customer();
        $this->actingAs($customer, 'customer');

        return $customer;
    }

    /** Admin with exactly the given permissions (through a dedicated role). */
    protected function admin(array $permissions = [], ?AdminRole $role = null, array $attributes = []): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = AdminUser::factory()->create($attributes);
        if ($role !== null) {
            $admin->assignRole($role->value);
        }
        if ($permissions !== []) {
            $custom = Role::findOrCreate('test-role-'.$admin->id, AdminPermission::GUARD);
            $custom->syncPermissions(array_map(static fn (AdminPermission|string $p): string => $p instanceof AdminPermission ? $p->value : $p, $permissions));
            $admin->assignRole($custom);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->refresh();
    }

    protected function actingAsAdmin(array $permissions = [], ?AdminRole $role = null): AdminUser
    {
        $admin = $this->admin($permissions, $role);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    protected function superAdmin(): AdminUser
    {
        return $this->actingAsAdmin([], AdminRole::SuperAdmin);
    }

    /** Simulates a new HTTP request from a fresh process: guards forget their cached user. */
    protected function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function assertApiError(TestResponse $response, int $status, string $code): TestResponse
    {
        return $response->assertStatus($status)->assertJson(['code' => $code]);
    }
}
