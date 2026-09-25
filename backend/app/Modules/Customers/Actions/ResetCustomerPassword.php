<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Events\CustomerPasswordReset;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Single-use token (60 min). Other sessions are invalidated because the stored
 * password hash changes (Sanctum AuthenticateSession) and the remember token rotates.
 */
final class ResetCustomerPassword
{
    public function handle(string $email, string $token, string $password): void
    {
        $status = Password::broker('customers')->reset(
            ['email' => mb_strtolower(trim($email)), 'token' => $token, 'password' => $password],
            static function (Customer $customer, string $password): void {
                $customer->password = $password;
                $customer->setRememberToken(Str::random(60));
                $customer->save();
                event(new CustomerPasswordReset($customer->id));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'Este link expirou ou é inválido.']);
        }
    }
}
