<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Models\Order;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /admin/orders/{id}/reveal-document — audited (order.sensitive_viewed). */
final class OrderSensitiveDataController
{
    use LoadsAdminOrder;

    public function store(Request $request, Order $order, AuditLogger $audit): JsonResponse
    {
        $audit->record(new AuditEntry($this->actor($request), 'order.sensitive_viewed', 'order', $order->id, null, ['fields' => ['customer_document', 'picked_up_by_document']]));

        return new JsonResponse(['data' => [
            'customer_document' => $order->customer_document,
            'picked_up_by_document' => $order->picked_up_by_document,
        ]]);
    }
}
