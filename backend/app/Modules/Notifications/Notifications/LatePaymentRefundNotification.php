<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Models\Order;

/** Pagamento recebido após o prazo sem possibilidade de reativar o pedido: estorno automático (RN-PAG-012). */
final class LatePaymentRefundNotification extends OrderNotification
{
    public function type(): string
    {
        return 'late_payment_refund';
    }

    protected function subject(Order $order): string
    {
        return 'Pagamento recebido após o prazo — pedido '.$order->number;
    }

    protected function lines(Order $order): array
    {
        return [
            'Recebemos o seu PIX depois que o pedido já havia sido cancelado e não foi possível reativá-lo.',
            'O valor será estornado automaticamente; você receberá a confirmação por e-mail.',
        ];
    }
}
