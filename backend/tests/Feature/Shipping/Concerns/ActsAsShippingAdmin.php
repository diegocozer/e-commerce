<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping\Concerns;

use App\Modules\Identity\Models\AdminUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

trait ActsAsShippingAdmin
{
    protected function shippingAdmin(bool $withPermission = true): AdminUser
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('shipping.manage', 'admin');
        Permission::findOrCreate('orders.view', 'admin');
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo($withPermission ? 'shipping.manage' : 'orders.view');
        $this->actingAs($admin, 'admin');

        return $admin;
    }
}
