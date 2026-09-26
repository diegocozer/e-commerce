<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Http\Resources\Admin\AdminOrderResource;
use App\Modules\Orders\Models\Order;
use App\Shared\Domain\ActorRef;
use Illuminate\Http\Request;

trait LoadsAdminOrder
{
    protected function present(Order $order): AdminOrderResource
    {
        return new AdminOrderResource($order->refresh()->load([
            'customer', 'items', 'statusHistory', 'payments.transactions', 'payments.refunds',
        ]));
    }

    protected function actor(Request $request): ActorRef
    {
        return ActorRef::admin((int) $request->user('admin')?->getAuthIdentifier());
    }

    /** 403 forbidden unless the admin has one of the permissions. */
    protected function requireAny(Request $request, string ...$permissions): void
    {
        $admin = $request->user('admin');
        foreach ($permissions as $permission) {
            if ($admin !== null && $admin->can($permission)) {
                return;
            }
        }
        abort(403);
    }
}
