<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\ResetCustomerPassword;
use App\Modules\Customers\Http\Requests\Store\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;

final class NewPasswordController
{
    public function store(ResetPasswordRequest $request, ResetCustomerPassword $reset): JsonResponse
    {
        $reset->handle((string) $request->validated('email'), (string) $request->validated('token'), (string) $request->validated('password'));

        return new JsonResponse(['data' => ['message' => 'Senha alterada.']]);
    }
}
