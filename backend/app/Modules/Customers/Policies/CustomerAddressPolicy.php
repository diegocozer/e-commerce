<?php

declare(strict_types=1);

namespace App\Modules\Customers\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;

/** SECURITY.md §4.1 — defence in depth; queries are already scoped to the customer (404). */
final class CustomerAddressPolicy
{
    public function view(Customer $customer, CustomerAddress $address): bool
    {
        return $address->customer_id === $customer->id;
    }

    public function update(Customer $customer, CustomerAddress $address): bool
    {
        return $address->customer_id === $customer->id;
    }

    public function delete(Customer $customer, CustomerAddress $address): bool
    {
        return $address->customer_id === $customer->id;
    }
}
