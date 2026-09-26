<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Enums\CancelReasonCode;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\Admin\AdminCancelOrderRequest;
use App\Modules\Orders\Http\Resources\Admin\AdminOrderResource;
use App\Modules\Orders\Models\Order;

/** POST /admin/orders/{id}/cancel — cancel_unpaid (pending) / cancel_paid (paid, processing, with refund). */
final class OrderCancellationController
{
    use LoadsAdminOrder;

    public function store(AdminCancelOrderRequest $request, Order $order, CancelOrder $cancel): AdminOrderResource
    {
        $order->status === OrderStatus::PendingPayment
            ? $this->requireAny($request, 'orders.cancel_unpaid')
            : $this->requireAny($request, 'orders.cancel_paid');

        $cancel->execute($order, CancelReasonCode::Admin, $this->actor($request), (string) $request->validated('reason'));

        return $this->present($order);
    }
}
