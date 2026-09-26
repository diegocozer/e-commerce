<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Customer;

use App\Modules\Orders\Http\Resources\Customer\OrderStatusPollResource;
use Illuminate\Http\Request;

/** GET /me/orders/{uuid}/status — light polling while the PIX is pending. */
final class OrderStatusController
{
    use ResolvesCustomerOrder;

    public function show(Request $request, string $uuid): OrderStatusPollResource
    {
        return new OrderStatusPollResource($this->customerOrder($request, $uuid, ['payments']));
    }
}
