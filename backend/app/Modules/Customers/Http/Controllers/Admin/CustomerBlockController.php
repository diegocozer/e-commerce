<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Admin;

use App\Modules\Customers\Actions\AdminCustomerActions;
use App\Modules\Customers\Http\Resources\AdminCustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Shared\Support\PlainText;
use Illuminate\Http\Request;

final class CustomerBlockController
{
    public function block(Request $request, int $customer, AdminCustomerActions $actions): AdminCustomerResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $actions->block(CustomerController::actor($request), Customer::query()->findOrFail($customer), (string) PlainText::clean($data['reason']));

        return (new CustomerController)->show($customer);
    }

    public function unblock(Request $request, int $customer, AdminCustomerActions $actions): AdminCustomerResource
    {
        $actions->unblock(CustomerController::actor($request), Customer::query()->findOrFail($customer));

        return (new CustomerController)->show($customer);
    }
}
