<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentOrderContextProvider;
use App\Modules\Payments\Contracts\PaymentService;
use App\Modules\Payments\DTOs\PaymentData;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentGatewayUnavailable;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * POST /me/orders/{uuid}/payment (API.md §3.D): returns the usable pending PIX,
 * initiates a pending payment that never reached the gateway, or creates a new
 * payment (previous failed/expired). The new PIX expires at orders.expires_at.
 * The gateway is called outside the transaction.
 */
final class RetryOrderPayment
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentOrderContextProvider $contexts,
    ) {}

    /**
     * @return array{payment: PaymentData, created: bool}
     *
     * @throws InvalidOrderTransition|PaymentGatewayUnavailable
     */
    public function execute(Order $order): array
    {
        [$paymentId, $created] = DB::transaction(function () use ($order): array {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== OrderStatus::PendingPayment || $locked->expires_at === null || ! $locked->expires_at->isFuture()) {
                throw InvalidOrderTransition::because('O prazo de pagamento deste pedido terminou ou o pedido não aguarda pagamento.');
            }

            $latest = $this->payments->latestForOrder($locked->id);
            if ($latest !== null && $latest->status === PaymentStatus::Pending) {
                return [$latest->id, false];
            }

            $context = $this->contexts->forOrder($locked->id);
            $payment = $this->payments->createPending(new PaymentRequest(
                orderId: $locked->id,
                orderNumber: $locked->number,
                method: $locked->payment_method,
                amount: Money::ofCents($locked->total_cents),
                payer: $context->payer,
                expiresAt: CarbonImmutable::instance($locked->expires_at),
            ));

            return [$payment->id, true];
        });

        try {
            $payment = $this->payments->initiate($paymentId);
        } catch (PaymentGatewayUnavailable $e) {
            throw $e->withDetails(['order' => ['uuid' => $order->uuid, 'number' => $order->number]]);
        }

        return ['payment' => $payment, 'created' => $created];
    }
}
