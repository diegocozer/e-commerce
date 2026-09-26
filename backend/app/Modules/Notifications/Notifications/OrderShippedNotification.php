<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Models\Order;

/** Pedido enviado / saiu para entrega. */
final class OrderShippedNotification extends OrderNotification
{
    public function type(): string
    {
        return 'order_shipped';
    }

    protected function subject(Order $order): string
    {
        return 'Seu pedido '.$order->number.' foi enviado';
    }

    protected function lines(Order $order): array
    {
        return array_values(array_filter([
            'Seu pedido saiu para entrega ('.$order->shipping_method_name.').',
            $order->tracking_code !== null ? 'Código de rastreio: '.$order->tracking_code.($order->tracking_url !== null ? ' — '.$order->tracking_url : '') : null,
            $order->estimated_delivery_date !== null ? 'Previsão de entrega: '.$order->estimated_delivery_date->format('d/m/Y').'.' : null,
        ]));
    }

    protected function whatsAppTemplate(): ?string
    {
        return 'order_shipped';
    }
}
