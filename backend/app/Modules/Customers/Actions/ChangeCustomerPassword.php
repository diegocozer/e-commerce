<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Str;

/** PUT /me/password — other sessions fall because the session password hash no longer matches. */
final class ChangeCustomerPassword
{
    public function handle(Customer $customer, string $password): void
    {
        $customer->password = $password;
        $customer->setRememberToken(Str::random(60));
        $customer->save();
    }
}
