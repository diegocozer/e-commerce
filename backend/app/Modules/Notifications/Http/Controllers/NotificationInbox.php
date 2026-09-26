<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Http\Resources\AppNotificationResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Notifications\HasDatabaseNotifications;

/** Shared inbox logic for /me/notifications and /admin/notifications (only the authenticated user's). */
trait NotificationInbox
{
    abstract protected function guard(): string;

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'unread' => ['nullable', 'in:0,1,true,false'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        /** @var Model&HasDatabaseNotifications $user */
        $user = $request->user($this->guard());
        $query = $user->notifications()->latest()->orderByDesc('id');
        if (in_array((string) ($data['unread'] ?? ''), ['1', 'true'], true)) {
            $query->whereNull('read_at');
        }

        return AppNotificationResource::collection($query->paginate((int) ($data['per_page'] ?? 20))->withQueryString());
    }

    public function markRead(Request $request): Response
    {
        $data = $request->validate(['ids' => ['sometimes', 'array', 'max:200'], 'ids.*' => ['uuid']]);
        $user = $request->user($this->guard());
        $query = $user->unreadNotifications();
        if (isset($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        }
        $query->update(['read_at' => now()]);

        return response()->noContent();
    }
}
