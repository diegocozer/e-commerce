<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Exceptions;

use App\Shared\Exceptions\DomainException;
use App\Shared\Exceptions\ErrorCode;

/** 503 postal_code_lookup_unavailable (API.md §3.A GET /postal-codes/{cep}). */
final class PostalCodeLookupUnavailable extends DomainException
{
    protected string $errorCode = ErrorCode::PostalCodeLookupUnavailable->value;

    protected int $httpStatus = 503;

    protected function defaultMessage(): string
    {
        return 'Não foi possível consultar o CEP agora. Tente novamente em instantes.';
    }
}
