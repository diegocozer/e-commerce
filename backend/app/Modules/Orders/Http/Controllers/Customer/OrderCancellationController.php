<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Customer;

use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Enums\CancelReasonCode;
use App\Modules\Orders\Http\Requests\Customer\CancelOrderRequest;
use App\Modules\Orders\Http\Resources\Customer\OrderDetailResource;
use App\Shared\Domain\ActorRef;

/** POST /me/orders/{uuid}/cancel — only pending_payment (RN-PED-020). */
final class OrderCancellationController
{
    use ResolvesCustomerOrder;

    public function store(CancelOrderRequest $request, string $uuid, CancelOrder $cancel): OrderDetailResource
    {
        $order = $this->customerOrder($request, $uuid);
        $cancel->execute($order, CancelReasonCode::Customer, ActorRef::customer($order->customer_id), $request->validated('reason'));

        return new OrderDetailResource($order->refresh()->load(self::detailRelations()));
    }
}
