<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\Exceptions\DomainException;

/** Lookup service unavailable (timeout, network, 5xx) → 503 postal_code_lookup_unavailable. */
final class PostalCodeLookupUnavailable extends DomainException
{
    protected string $errorCode = 'postal_code_lookup_unavailable';

    protected int $httpStatus = 503;

    protected function defaultMessage(): string
    {
        return 'Não foi possível consultar o CEP agora. Tente novamente em instantes.';
    }
}
