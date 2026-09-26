<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Shared\Exceptions\DomainException;

final class AccountDisabled extends DomainException
{
    protected string $errorCode = 'account_disabled';

    protected int $httpStatus = 403;

    protected function defaultMessage(): string
    {
        return 'Sua conta está desativada. Fale com um administrador.';
    }
}
