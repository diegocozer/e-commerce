<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Shared\Exceptions\DomainException;

/** 409 resource_in_use with blockers (API.md §1.6). */
final class ResourceInUse extends DomainException
{
    protected string $errorCode = 'resource_in_use';

    protected int $httpStatus = 409;

    /** @param  list<array{type: string, id: int, label: string}>  $blockers */
    public static function because(string $message, array $blockers): self
    {
        return new self($message, details: ['blockers' => $blockers]);
    }
}
