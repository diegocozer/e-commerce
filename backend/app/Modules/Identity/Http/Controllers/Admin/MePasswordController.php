<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\AdminSession;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class MePasswordController
{
    public function update(ChangePasswordRequest $request, AuditLogger $audit): Response
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        $admin->password = (string) $request->validated('password');
        $admin->setRememberToken(Str::random(60));
        $admin->save();

        $request->session()->regenerate();
        AdminSession::rememberPassword($request, $admin);
        $audit->record(new AuditEntry(ActorRef::admin($admin->id), 'admin_user.password_changed', 'admin_user', $admin->id));

        return response()->noContent();
    }
}
