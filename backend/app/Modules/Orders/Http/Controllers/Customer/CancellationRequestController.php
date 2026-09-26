<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Customer;

use App\Modules\Orders\Actions\RequestOrderCancellation;
use App\Modules\Orders\Http\Requests\Customer\CancellationRequestRequest;
use App\Modules\Orders\Http\Resources\Customer\OrderDetailResource;

/** POST /me/orders/{uuid}/cancellation-request — paid orders (status does not change). */
final class CancellationRequestController
{
    use ResolvesCustomerOrder;

    public function store(CancellationRequestRequest $request, string $uuid, RequestOrderCancellation $action): OrderDetailResource
    {
        $order = $this->customerOrder($request, $uuid);
        $action->execute($order, (string) $request->validated('reason'));

        return new OrderDetailResource($order->refresh()->load(self::detailRelations()));
    }
}
