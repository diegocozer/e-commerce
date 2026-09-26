<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Admin;

use App\Modules\Customers\Actions\AdminCustomerActions;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerPasswordResetController
{
    public function store(Request $request, int $customer, AdminCustomerActions $actions): JsonResponse
    {
        $actions->sendPasswordReset(CustomerController::actor($request), Customer::query()->findOrFail($customer));

        return new JsonResponse(['data' => ['message' => 'Link de redefinição enviado ao cliente.']], 202);
    }
}
