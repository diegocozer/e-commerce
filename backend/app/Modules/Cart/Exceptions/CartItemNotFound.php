<?php

declare(strict_types=1);

namespace App\Modules\Cart\Exceptions;

use App\Shared\Exceptions\DomainException;

/** 404 not_found: cart item of another cart (never reveals existence). */
final class CartItemNotFound extends DomainException
{
    protected string $errorCode = 'not_found';

    protected int $httpStatus = 404;

    protected function defaultMessage(): string
    {
        return 'Recurso não encontrado.';
    }
}
