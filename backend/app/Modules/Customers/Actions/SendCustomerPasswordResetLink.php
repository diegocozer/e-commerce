<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Notifications\CustomerResetPasswordNotification;
use Illuminate\Support\Facades\Password;

/** RN-CLI-008: neutral response whatever happens (unknown e-mail, throttled…). */
final class SendCustomerPasswordResetLink
{
    public function handle(string $email): void
    {
        Password::broker('customers')->sendResetLink(
            ['email' => mb_strtolower(trim($email))],
            static function (Customer $customer, string $token): void {
                $customer->notify(new CustomerResetPasswordNotification($token));
            },
        );
    }
}
