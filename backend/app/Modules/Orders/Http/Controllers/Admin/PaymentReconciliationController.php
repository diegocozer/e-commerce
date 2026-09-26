<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Http\Resources\Admin\AdminOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentService;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * POST /admin/orders/{id}/payments/reconcile — syncFromGateway of the latest
 * payment. Lives in Orders because it answers with AdminOrder (Payments does
 * not depend on Orders).
 */
final class PaymentReconciliationController
{
    use LoadsAdminOrder;

    public function store(Request $request, Order $order, PaymentService $payments, AuditLogger $audit): AdminOrderResource
    {
        $payment = $payments->latestForOrder($order->id);
        if ($payment !== null && $payment->externalId !== null) {
            $payments->syncFromGateway($payment->provider->value, $payment->externalId);
        }
        $audit->record(new AuditEntry($this->actor($request), 'payment.reconciled', 'order', $order->id));

        return $this->present($order);
    }
}
