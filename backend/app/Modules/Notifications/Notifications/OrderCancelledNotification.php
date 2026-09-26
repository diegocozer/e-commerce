<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Models\Order;

/** Pedido cancelado (motivo incluído; link para comprar novamente). */
final class OrderCancelledNotification extends OrderNotification
{
    public function __construct(int $orderId, public readonly string $reason = 'admin', public readonly bool $wasPaid = false)
    {
        parent::__construct($orderId);
    }

    public function type(): string
    {
        return 'order_cancelled';
    }

    protected function subject(Order $order): string
    {
        return 'Pedido '.$order->number.' cancelado';
    }

    protected function lines(Order $order): array
    {
        return array_values(array_filter([
            match ($this->reason) {
                'payment_expired' => 'O pagamento não foi identificado dentro do prazo e o pedido foi cancelado.',
                'customer' => 'Seu pedido foi cancelado conforme solicitado.',
                'payment_failed' => 'O pedido foi cancelado porque o pagamento não foi aprovado.',
                default => 'Seu pedido foi cancelado pela loja.'.($order->cancel_reason !== null ? ' Motivo: '.$order->cancel_reason : ''),
            },
            $this->wasPaid ? 'O estorno do valor pago foi solicitado e você receberá a confirmação por e-mail.' : null,
            'Se ainda precisar dos itens, use "Comprar novamente" na página do pedido.',
        ]));
    }
}
