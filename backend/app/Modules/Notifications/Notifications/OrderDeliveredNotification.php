<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Models\Order;

/** Pedido entregue ou retirado. */
final class OrderDeliveredNotification extends OrderNotification
{
    public function type(): string
    {
        return 'order_delivered';
    }

    protected function subject(Order $order): string
    {
        return $order->shipping_method_type === 'pickup'
            ? 'Pedido '.$order->number.' retirado'
            : 'Pedido '.$order->number.' entregue';
    }

    protected function lines(Order $order): array
    {
        return ['Obrigado pela compra! Esperamos que os materiais atendam bem o seu trabalho.'];
    }
}
