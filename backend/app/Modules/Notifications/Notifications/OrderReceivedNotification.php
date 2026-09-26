<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Models\Order;

/** Pedido criado (pending_payment) com instruções PIX. */
final class OrderReceivedNotification extends OrderNotification
{
    public function type(): string
    {
        return 'order_created';
    }

    protected function subject(Order $order): string
    {
        return 'Recebemos seu pedido '.$order->number;
    }

    protected function lines(Order $order): array
    {
        return [
            'Seu pedido foi registrado e aguarda o pagamento via PIX.',
            $order->expires_at !== null
                ? 'Pague até '.self::localTime($order->expires_at).' (horário de Brasília); depois disso o pedido é cancelado automaticamente.'
                : 'Acompanhe o pagamento pela página do pedido.',
        ];
    }

    protected function pix(Order $order): ?array
    {
        $payment = $order->payments->sortByDesc('id')->first();
        if ($payment === null || $payment->pix_copy_paste === null || $payment->status->value !== 'pending') {
            return null;
        }

        return ['copy_paste' => $payment->pix_copy_paste, 'expires_at' => self::localTime($payment->expires_at)];
    }
}
