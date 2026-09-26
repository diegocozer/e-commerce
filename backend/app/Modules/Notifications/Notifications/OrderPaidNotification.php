<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Models\Order;

/** Pagamento aprovado (inclusive reativação por pagamento tardio). */
final class OrderPaidNotification extends OrderNotification
{
    public function __construct(int $orderId, public readonly bool $reactivated = false)
    {
        parent::__construct($orderId);
    }

    public function type(): string
    {
        return 'order_paid';
    }

    protected function subject(Order $order): string
    {
        return 'Pagamento aprovado — pedido '.$order->number;
    }

    protected function lines(Order $order): array
    {
        return array_values(array_filter([
            'Recebemos o pagamento do seu pedido. Agora vamos separar os itens.',
            $this->reactivated ? 'O pagamento chegou depois do prazo, mas ainda havia estoque e o seu pedido foi reativado.' : null,
            $order->shipping_method_type === 'pickup'
                ? 'Avisaremos quando estiver pronto para retirada.'
                : ($order->estimated_delivery_date !== null ? 'Previsão de entrega: '.$order->estimated_delivery_date->format('d/m/Y').'.' : null),
        ]));
    }

    protected function whatsAppTemplate(): ?string
    {
        return 'order_paid';
    }
}
