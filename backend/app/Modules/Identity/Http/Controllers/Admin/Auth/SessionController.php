<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin\Auth;

use App\Modules\Identity\Actions\LoginAdmin;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\AdminMeResource;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Support\AdminSession;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SessionController
{
    public function store(LoginRequest $request, LoginAdmin $login): AdminMeResource
    {
        $admin = $login->handle((string) $request->validated('email'), (string) $request->validated('password'), $request->ip());
        AdminSession::start($request, $admin);

        return new AdminMeResource($admin);
    }

    public function destroy(Request $request, AuditLogger $audit): Response
    {
        $admin = $request->user('admin');
        if ($admin instanceof AdminUser) {
            $audit->record(new AuditEntry(ActorRef::admin($admin->id), 'admin_user.logout', 'admin_user', $admin->id));
        }
        AdminSession::end($request);

        return response()->noContent();
    }
}
