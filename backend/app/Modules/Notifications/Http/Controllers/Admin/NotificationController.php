<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Http\Controllers\NotificationInbox;

/** GET /admin/notifications · POST /admin/notifications/read */
final class NotificationController
{
    use NotificationInbox;

    protected function guard(): string
    {
        return 'admin';
    }
}
