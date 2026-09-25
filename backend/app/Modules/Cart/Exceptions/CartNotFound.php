<?php

declare(strict_types=1);

namespace App\Modules\Cart\Exceptions;

use App\Shared\Exceptions\DomainException;

/** 404 cart_not_found: unknown, expired, converted or customer-owned X-Cart-Token (SEC-IDOR-05). */
final class CartNotFound extends DomainException
{
    protected string $errorCode = 'cart_not_found';

    protected int $httpStatus = 404;

    protected function defaultMessage(): string
    {
        return 'Carrinho não encontrado.';
    }
}
