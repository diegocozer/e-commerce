<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Shared\Exceptions\DomainException;

/** Login of a blocked/disabled account → 403 account_disabled (API.md §1.2). */
final class AccountDisabled extends DomainException
{
    protected string $errorCode = 'account_disabled';

    protected int $httpStatus = 403;

    protected function defaultMessage(): string
    {
        return 'Sua conta está desativada. Entre em contato com a loja.';
    }
}
