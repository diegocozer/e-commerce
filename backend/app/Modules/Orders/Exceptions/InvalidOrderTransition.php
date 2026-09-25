<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Shared\Exceptions\DomainException;

/** 409 invalid_status_transition with `allowed_transitions` (API.md §1.6 / §4.8). */
final class InvalidOrderTransition extends DomainException
{
    protected string $errorCode = 'invalid_status_transition';

    /** @param list<OrderStatus> $allowed */
    public static function between(OrderStatus $from, OrderStatus $to, array $allowed = []): self
    {
        return new self(
            sprintf('Transição de status inválida: %s → %s.', mb_strtolower($from->label()), mb_strtolower($to->label())),
            details: ['allowed_transitions' => array_map(static fn (OrderStatus $s): string => $s->value, $allowed)],
        );
    }

    /** @param list<OrderStatus> $allowed */
    public static function because(string $message, array $allowed = []): self
    {
        return new self($message, details: ['allowed_transitions' => array_map(static fn (OrderStatus $s): string => $s->value, $allowed)]);
    }
}
