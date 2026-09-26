<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin\Auth;

use App\Modules\Identity\Actions\AdminPasswordResets;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;

final class PasswordResetLinkController
{
    public function store(ForgotPasswordRequest $request, AdminPasswordResets $resets): JsonResponse
    {
        $resets->sendLink((string) $request->validated('email'));

        return new JsonResponse(['data' => ['message' => 'Se o e-mail existir, enviaremos instruções.']]);
    }
}
