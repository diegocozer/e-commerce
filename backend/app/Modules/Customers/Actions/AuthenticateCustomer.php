<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Exceptions\AccountDisabled;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Checks the credentials (neutral message) and the account status (RN-CLI-008/010). */
final class AuthenticateCustomer
{
    public const string INVALID = 'E-mail ou senha inválidos.';

    public function handle(string $email, string $password): Customer
    {
        $customer = Customer::query()->where('email', mb_strtolower(trim($email)))->first();

        if ($customer === null) {
            Hash::check($password, '$2y$04$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // constant time-ish

            throw ValidationException::withMessages(['email' => self::INVALID]);
        }

        if (! Hash::check($password, $customer->password)) {
            throw ValidationException::withMessages(['email' => self::INVALID]);
        }

        if (! $customer->is_active || $customer->anonymized_at !== null) {
            throw new AccountDisabled;
        }

        if (Hash::needsRehash($customer->password)) {
            $customer->password = $password;
        }
        $customer->last_login_at = now();
        $customer->save();

        return $customer;
    }
}
