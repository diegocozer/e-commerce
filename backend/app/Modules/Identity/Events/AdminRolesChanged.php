<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

final readonly class AdminRolesChanged
{
    /** @param  list<string>  $roles */
    public function __construct(public int $adminUserId, public array $roles, public ?int $byAdminId) {}
}
