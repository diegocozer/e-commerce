<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Shared\Exceptions\DomainException;

final class ResourceInUse extends DomainException
{
    protected string $errorCode = 'resource_in_use';

    protected int $httpStatus = 409;

    protected function defaultMessage(): string
    {
        return 'Recurso em uso.';
    }
}
