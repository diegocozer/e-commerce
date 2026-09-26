<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Customer;

use App\Modules\Orders\Http\Requests\Customer\ListOrdersRequest;
use App\Modules\Orders\Http\Resources\Customer\OrderDetailResource;
use App\Modules\Orders\Http\Resources\Customer\OrderSummaryResource;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** GET /me/orders · GET /me/orders/{uuid} */
final class OrderController
{
    use ResolvesCustomerOrder;

    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $statuses = $request->validated('status') ?? [];
        $orders = Order::query()
            ->where('customer_id', (int) $request->user('customer')?->getAuthIdentifier())
            ->when($statuses !== [], static fn ($q) => $q->whereIn('status', $statuses))
            ->withCount('items')
            ->with('payments')
            ->orderByDesc('placed_at')->orderByDesc('id')
            ->paginate((int) ($request->validated('per_page') ?? 10))
            ->withQueryString();

        return OrderSummaryResource::collection($orders);
    }

    public function show(Request $request, string $uuid): OrderDetailResource
    {
        return new OrderDetailResource($this->customerOrder($request, $uuid, self::detailRelations()));
    }
}
