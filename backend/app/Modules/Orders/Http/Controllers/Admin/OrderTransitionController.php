<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\ChangeOrderStatus;
use App\Modules\Orders\DTOs\StatusChangeData;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\Admin\OrderTransitionRequest;
use App\Modules\Orders\Http\Resources\Admin\AdminOrderResource;
use App\Modules\Orders\Models\Order;
use Illuminate\Validation\ValidationException;

/** POST /admin/orders/{id}/transitions (API.md §3.G.9). */
final class OrderTransitionController
{
    use LoadsAdminOrder;

    public function store(OrderTransitionRequest $request, Order $order, ChangeOrderStatus $action): AdminOrderResource
    {
        $to = OrderStatus::from((string) $request->validated('to_status'));
        $to === OrderStatus::PickedUp
            ? $this->requireAny($request, 'orders.fulfill', 'orders.pickup')
            : $this->requireAny($request, 'orders.fulfill');

        if ($to === OrderStatus::Shipped && $order->shipping_method_type === 'carrier' && blank($request->validated('tracking_code'))) {
            throw ValidationException::withMessages(['tracking_code' => ['Informe o código de rastreio para envio por transportadora.']]);
        }

        $action->execute($order, $to, $this->actor($request), new StatusChangeData(
            note: $request->validated('note'),
            trackingCode: $request->validated('tracking_code'),
            trackingUrl: $request->validated('tracking_url'),
            carrierName: $request->validated('carrier_name'),
            pickedUpByName: $request->validated('picked_up_by_name'),
            pickedUpByDocument: $request->pickedUpDocument(),
        ));

        return $this->present($order);
    }
}
