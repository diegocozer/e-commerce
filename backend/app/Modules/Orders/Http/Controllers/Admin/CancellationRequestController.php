<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\DismissCancellationRequest as DismissAction;
use App\Modules\Orders\Http\Requests\Admin\DismissCancellationRequest;
use App\Modules\Orders\Http\Resources\Admin\AdminOrderResource;
use App\Modules\Orders\Models\Order;

/** POST /admin/orders/{id}/cancellation-request/dismiss */
final class CancellationRequestController
{
    use LoadsAdminOrder;

    public function dismiss(DismissCancellationRequest $request, Order $order, DismissAction $action): AdminOrderResource
    {
        $action->execute($order, (string) $request->validated('note'), $this->actor($request));

        return $this->present($order);
    }
}
