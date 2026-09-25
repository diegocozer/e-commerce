<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;

/** PATCH /me: name, phone, marketing consent (with timestamp), CPF only when still empty. */
final class UpdateCustomerProfile
{
    /** @param  array<string, mixed>  $data */
    public function handle(Customer $customer, array $data): Customer
    {
        if (array_key_exists('name', $data)) {
            $customer->name = trim((string) $data['name']);
        }
        if (array_key_exists('phone', $data)) {
            $customer->phone = (string) $data['phone'];
        }
        if (array_key_exists('cpf', $data) && $customer->cpf === null) {
            $customer->cpf = (string) $data['cpf'];
        }
        if (array_key_exists('marketing_opt_in', $data)) {
            $optIn = (bool) $data['marketing_opt_in'];
            if ($optIn !== (bool) $customer->marketing_opt_in) {
                $customer->marketing_opt_in = $optIn;
                $customer->marketing_opt_in_at = now();
            }
        }
        $customer->save();

        return $customer;
    }
}
