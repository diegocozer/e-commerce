<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Dev;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\NoPendingPayment;
use App\Modules\Payments\Services\SandboxSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/dev/payments/{order_uuid}/approve|fail — registered only in
 * local/testing with the sandbox driver (API.md §3.F). The order is looked up
 * scoped to the authenticated customer (404 otherwise); Payments knows orders
 * only by id, hence the scoped id lookup.
 */
final class SandboxPaymentController
{
    public function __construct(private readonly SandboxSimulator $simulator) {}

    public function approve(Request $request, string $orderUuid): JsonResponse
    {
        $data = $request->validate(['amount_cents' => ['sometimes', 'integer', 'min:1']]);

        return $this->simulate($request, $orderUuid, PaymentStatus::Approved, isset($data['amount_cents']) ? (int) $data['amount_cents'] : null);
    }

    public function fail(Request $request, string $orderUuid): JsonResponse
    {
        return $this->simulate($request, $orderUuid, PaymentStatus::Failed, null);
    }

    private function simulate(Request $request, string $orderUuid, PaymentStatus $status, ?int $amountCents): JsonResponse
    {
        $orderId = DB::table('orders')
            ->where('uuid', $orderUuid)
            ->where('customer_id', (int) $request->user('customer')?->getAuthIdentifier())
            ->value('id');
        abort_if($orderId === null, 404);

        $payment = $this->simulator->pendingPayment((int) $orderId);
        if ($payment === null) {
            throw new NoPendingPayment;
        }

        $eventId = $this->simulator->simulate($payment, $status, $amountCents);

        return new JsonResponse(['data' => ['status' => 'dispatched', 'webhook_event_external_id' => $eventId]], 202);
    }
}
