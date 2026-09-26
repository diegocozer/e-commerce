<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use Spatie\Permission\PermissionRegistrar;

final class ForgetPermissionCache
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function handle(object $event): void
    {
        $this->registrar->forgetCachedPermissions();
    }
}
