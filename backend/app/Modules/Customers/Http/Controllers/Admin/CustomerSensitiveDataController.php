<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Admin;

use App\Modules\Customers\Actions\AdminCustomerActions;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerSensitiveDataController
{
    public function store(Request $request, int $customer, AdminCustomerActions $actions): JsonResponse
    {
        $model = Customer::withTrashed()->with('company')->findOrFail($customer);

        return new JsonResponse(['data' => $actions->revealDocument(CustomerController::actor($request), $model)]);
    }
}
