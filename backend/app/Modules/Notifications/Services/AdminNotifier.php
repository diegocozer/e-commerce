<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Notifications\Notifications\AdminAlertNotification;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use Illuminate\Support\Facades\Notification;

/** Sends panel alerts to active admins holding a permission (+ alert e-mails from settings for anomalies). */
final class AdminNotifier
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @param list<string> $permissions any of */
    public function notify(array $permissions, AdminAlertNotification $notification, bool $includeAlertEmails = false): void
    {
        $admins = AdminUser::query()->where('is_active', true)->get()
            ->filter(static function (AdminUser $admin) use ($permissions): bool {
                foreach ($permissions as $permission) {
                    if ($admin->can($permission)) {
                        return true;
                    }
                }

                return false;
            });
        if ($admins->isNotEmpty()) {
            Notification::send($admins, $notification);
        }

        if ($includeAlertEmails) {
            $known = $admins->pluck('email')->all();
            foreach ($this->settings->array(SettingKey::NotificationsAdminAlertEmails) as $email) {
                if (is_string($email) && $email !== '' && ! in_array(mb_strtolower($email), $known, true)) {
                    Notification::route('mail', $email)->notify($notification);
                }
            }
        }
    }
}
