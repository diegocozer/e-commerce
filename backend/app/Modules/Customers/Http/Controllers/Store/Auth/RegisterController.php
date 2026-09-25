<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\RegisterCustomer;
use App\Modules\Customers\Http\Requests\Store\RegisterRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Support\CustomerSession;
use Illuminate\Http\JsonResponse;

final class RegisterController
{
    public function store(RegisterRequest $request, RegisterCustomer $register, CustomerSession $session): JsonResponse
    {
        $customer = $register->handle($request->validated(), $request->ip());
        $merge = $session->start($request, $customer);

        return new JsonResponse(['data' => [
            'customer' => (new CustomerResource($customer))->resolve($request),
            'cart_merge' => $merge?->toArray(),
        ]], 201);
    }
}
