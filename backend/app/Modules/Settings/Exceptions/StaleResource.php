<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use App\Shared\Exceptions\DomainException;

final class StaleResource extends DomainException
{
    protected string $errorCode = 'stale_resource';

    protected function defaultMessage(): string
    {
        return 'As configurações foram alteradas por outra pessoa. Recarregue e tente novamente.';
    }
}
