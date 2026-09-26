<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Exceptions;

use App\Shared\Exceptions\DomainException;
use App\Shared\Exceptions\ErrorCode;

/** 404 not_found "CEP não encontrado." */
final class PostalCodeNotFound extends DomainException
{
    protected string $errorCode = ErrorCode::NotFound->value;

    protected int $httpStatus = 404;

    protected function defaultMessage(): string
    {
        return 'CEP não encontrado.';
    }
}
