<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\UpdateOrderDetails;
use App\Modules\Orders\Http\Requests\Admin\ListAdminOrdersRequest;
use App\Modules\Orders\Http\Requests\Admin\UpdateAdminOrderRequest;
use App\Modules\Orders\Http\Resources\Admin\AdminOrderListResource;
use App\Modules\Orders\Http\Resources\Admin\AdminOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\AdminOrderQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

/** GET /admin/orders · GET/PATCH /admin/orders/{id} */
final class OrderController
{
    use LoadsAdminOrder;

    public function index(ListAdminOrdersRequest $request): AnonymousResourceCollection
    {
        $query = AdminOrderQuery::filtered($request->validated());
        AdminOrderQuery::sort($query, $request->validated('sort') ?? '-placed_at');

        return AdminOrderListResource::collection(
            $query->withCount('items')->paginate((int) ($request->validated('per_page') ?? 25))->withQueryString(),
        );
    }

    public function show(Order $order): AdminOrderResource
    {
        return $this->present($order);
    }

    public function update(UpdateAdminOrderRequest $request, Order $order, UpdateOrderDetails $action): AdminOrderResource
    {
        $data = $request->validated();
        if (array_key_exists('internal_notes', $data)) {
            $this->requireAny($request, 'orders.notes');
        }
        if (array_key_exists('tracking_code', $data) || array_key_exists('tracking_url', $data)) {
            $this->requireAny($request, 'orders.fulfill');
        }
        $action->execute($order, $data, $this->actor($request));

        return $this->present($order);
    }
}
