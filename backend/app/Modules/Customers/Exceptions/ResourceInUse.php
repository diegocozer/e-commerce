<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Shared\Exceptions\DomainException;

final class ResourceInUse extends DomainException
{
    protected string $errorCode = 'resource_in_use';
}
