<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Models\Order;

/** Pedido pronto para retirada. */
final class OrderReadyForPickupNotification extends OrderNotification
{
    public function type(): string
    {
        return 'order_ready_for_pickup';
    }

    protected function subject(Order $order): string
    {
        return 'Pedido '.$order->number.' pronto para retirada';
    }

    protected function lines(Order $order): array
    {
        return [
            'Seu pedido está pronto para retirada na loja.',
            'Apresente o número do pedido e um documento com foto. Terceiros podem retirar informando nome e documento.',
        ];
    }

    protected function whatsAppTemplate(): ?string
    {
        return 'order_ready_for_pickup';
    }
}
