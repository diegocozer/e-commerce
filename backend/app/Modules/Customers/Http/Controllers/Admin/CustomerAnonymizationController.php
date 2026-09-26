<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Admin;

use App\Modules\Customers\Actions\AdminCustomerActions;
use App\Modules\Customers\Http\Resources\AdminCustomerResource;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Request;

final class CustomerAnonymizationController
{
    public function store(Request $request, int $customer, AdminCustomerActions $actions): AdminCustomerResource
    {
        $request->validate(['confirm' => ['accepted']]);
        $actions->anonymize(CustomerController::actor($request), Customer::query()->findOrFail($customer));

        return (new CustomerController)->show($customer);
    }
}
