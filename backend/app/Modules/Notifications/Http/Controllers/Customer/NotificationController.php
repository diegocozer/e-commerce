<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Customer;

use App\Modules\Notifications\Http\Controllers\NotificationInbox;

/** GET /me/notifications · POST /me/notifications/read */
final class NotificationController
{
    use NotificationInbox;

    protected function guard(): string
    {
        return 'customer';
    }
}
