<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Shared\Exceptions\DomainException;

final class Forbidden extends DomainException
{
    protected string $errorCode = 'forbidden';

    protected int $httpStatus = 403;

    protected function defaultMessage(): string
    {
        return 'Acesso negado.';
    }
}
