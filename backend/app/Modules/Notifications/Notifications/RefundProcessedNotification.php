<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Models\Order;

/** Estorno realizado. */
final class RefundProcessedNotification extends OrderNotification
{
    public function __construct(int $orderId, public readonly int $amountCents = 0)
    {
        parent::__construct($orderId);
    }

    public function type(): string
    {
        return 'refund_processed';
    }

    protected function subject(Order $order): string
    {
        return 'Estorno do pedido '.$order->number;
    }

    protected function lines(Order $order): array
    {
        return [
            'Estornamos '.self::money($this->amountCents).' referente ao pedido '.$order->number.'.',
            'O valor volta para a conta de origem do PIX; o prazo depende do seu banco.',
        ];
    }
}
