<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\Exceptions\DomainException;

/** No approved payment with refundable balance for the order. */
final class RefundNotAllowed extends DomainException
{
    protected string $errorCode = 'invalid_status_transition';

    protected function defaultMessage(): string
    {
        return 'Não há pagamento aprovado para estornar.';
    }
}
