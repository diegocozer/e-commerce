<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\Exceptions\DomainException;

/** Order has no pending payment to simulate (409 invalid_status_transition, API.md §3.F). */
final class NoPendingPayment extends DomainException
{
    protected string $errorCode = 'invalid_status_transition';

    protected function defaultMessage(): string
    {
        return 'O pedido não possui pagamento pendente.';
    }
}
