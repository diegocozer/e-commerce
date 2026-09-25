<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Shared\Exceptions\DomainException;

/** 409 too_many_pending_orders with `pending_orders: {uuid, number}[]` (ADR-021). */
final class TooManyPendingOrders extends DomainException
{
    protected string $errorCode = 'too_many_pending_orders';

    /** @param list<array{uuid: string, number: string}> $pending */
    public static function with(array $pending): self
    {
        return new self(
            'Você já possui 3 pedidos aguardando pagamento. Pague ou cancele um deles para continuar.',
            details: ['pending_orders' => $pending],
        );
    }
}
