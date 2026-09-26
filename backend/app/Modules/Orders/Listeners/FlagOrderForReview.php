<?php

declare(strict_types=1);

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Events\PaymentAmountMismatch;

/**
 * PaymentAmountMismatch (sync): the order is NOT paid; an internal note flags
 * it for finance review (RN-PAG-006). The panel flag `flags.amount_mismatch`
 * is derived from the payment transaction.
 */
final class FlagOrderForReview
{
    public function handle(PaymentAmountMismatch $event): void
    {
        $order = Order::query()->lockForUpdate()->find($event->orderId);
        if ($order === null) {
            return;
        }

        $note = sprintf('[Sistema] Divergência de valor no pagamento: esperado R$ %s, recebido R$ %s. Revisar com o financeiro.',
            number_format($event->expectedCents / 100, 2, ',', '.'), number_format($event->receivedCents / 100, 2, ',', '.'));
        $order->forceFill(['internal_notes' => trim(($order->internal_notes ?? '')."\n".$note)])->save();
    }
}
