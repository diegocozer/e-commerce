<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin\Auth;

use App\Modules\Identity\Actions\AdminPasswordResets;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Illuminate\Http\JsonResponse;

final class NewPasswordController
{
    public function store(ResetPasswordRequest $request, AdminPasswordResets $resets, AuditLogger $audit): JsonResponse
    {
        $admin = $resets->reset((string) $request->validated('email'), (string) $request->validated('token'), (string) $request->validated('password'));
        $audit->record(new AuditEntry(ActorRef::admin($admin->id), 'admin_user.password_reset', 'admin_user', $admin->id));

        return new JsonResponse(['data' => ['message' => 'Senha alterada.']]);
    }
}
