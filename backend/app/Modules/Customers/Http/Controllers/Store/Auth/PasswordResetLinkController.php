<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Actions\SendCustomerPasswordResetLink;
use App\Modules\Customers\Http\Requests\Store\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;

final class PasswordResetLinkController
{
    public function store(ForgotPasswordRequest $request, SendCustomerPasswordResetLink $send): JsonResponse
    {
        $send->handle((string) $request->validated('email'));

        return new JsonResponse(['data' => ['message' => 'Se o e-mail existir, enviaremos instruções.']]);
    }
}
