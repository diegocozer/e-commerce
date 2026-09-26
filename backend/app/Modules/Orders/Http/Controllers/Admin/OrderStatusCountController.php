<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\Admin\ListAdminOrdersRequest;
use App\Modules\Orders\Support\AdminOrderQuery;
use Illuminate\Http\JsonResponse;

/** GET /admin/orders/status-counts — same filters except status. */
final class OrderStatusCountController
{
    public function show(ListAdminOrdersRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['status']);

        $counts = AdminOrderQuery::filtered($filters)->toBase()
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $data = [];
        foreach (OrderStatus::cases() as $status) {
            $data[$status->value] = (int) ($counts[$status->value] ?? 0);
        }
        $data['all'] = array_sum($data);
        $data['cancellation_requests'] = AdminOrderQuery::filtered($filters)->whereNotNull('cancellation_requested_at')->count();

        return new JsonResponse(['data' => $data]);
    }
}
