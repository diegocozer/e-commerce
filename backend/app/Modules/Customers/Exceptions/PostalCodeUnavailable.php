<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Shared\Exceptions\DomainException;

/** CEP service down → 503 postal_code_lookup_unavailable; the address is not saved (RN-CLI-022). */
final class PostalCodeUnavailable extends DomainException
{
    protected string $errorCode = 'postal_code_lookup_unavailable';

    protected int $httpStatus = 503;

    protected function defaultMessage(): string
    {
        return 'Não foi possível consultar o CEP agora. Tente novamente em instantes.';
    }
}
