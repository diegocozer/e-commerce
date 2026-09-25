<?php

declare(strict_types=1);

namespace App\Modules\Cart\Exceptions;

use App\Shared\Exceptions\DomainException;

/** 409 cart_empty (shipping quote / checkout with no items). */
final class CartEmpty extends DomainException
{
    protected string $errorCode = 'cart_empty';

    protected int $httpStatus = 409;

    protected function defaultMessage(): string
    {
        return 'Seu carrinho está vazio.';
    }
}
