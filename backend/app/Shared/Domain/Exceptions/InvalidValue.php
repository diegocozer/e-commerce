<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

use InvalidArgumentException;

/**
 * Raised by value objects when raw input cannot be converted into a valid value
 * (e.g. "1e3" as a quantity, a CPF with a wrong check digit). Callers at the
 * HTTP boundary validate first; reaching this exception means a programming or
 * validation gap, so it is not rendered as a domain error.
 */
final class InvalidValue extends InvalidArgumentException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
