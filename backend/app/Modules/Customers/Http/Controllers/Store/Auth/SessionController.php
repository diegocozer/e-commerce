<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\AuthenticateCustomer;
use App\Modules\Customers\Http\Requests\Store\LoginRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Support\CustomerSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SessionController
{
    public function store(LoginRequest $request, AuthenticateCustomer $authenticate, CustomerSession $session): JsonResponse
    {
        $customer = $authenticate->handle((string) $request->validated('email'), (string) $request->validated('password'));
        $merge = $session->start($request, $customer, $request->boolean('remember'));

        return new JsonResponse(['data' => [
            'customer' => (new CustomerResource($customer))->resolve($request),
            'cart_merge' => $merge?->toArray(),
        ]]);
    }

    public function destroy(Request $request): Response
    {
        CustomerSession::end($request);

        return response()->noContent();
    }
}
