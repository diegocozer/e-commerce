<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Models\Order;

/** Pagamento recusado pelo gateway (pedido segue aguardando pagamento até expirar). */
final class PaymentFailedNotification extends OrderNotification
{
    public function type(): string
    {
        return 'payment_failed';
    }

    protected function subject(Order $order): string
    {
        return 'Pagamento não aprovado — pedido '.$order->number;
    }

    protected function lines(Order $order): array
    {
        return [
            'O pagamento do seu pedido não foi aprovado.',
            'Você pode gerar um novo PIX na página do pedido até '.self::localTime($order->expires_at).'.',
        ];
    }
}
