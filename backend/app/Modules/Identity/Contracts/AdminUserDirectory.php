<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\DTOs\AdminUserData;
use App\Modules\Identity\Enums\AdminPermission;

/** Read access to panel users for other modules (ARCHITECTURE.md §2.4 Identity). */
interface AdminUserDirectory
{
    public function find(int $adminUserId): ?AdminUserData;

    /** @return list<string> e-mails of active admins holding the permission (super-admins included) */
    public function emailsWithPermission(AdminPermission $permission): array;
}
