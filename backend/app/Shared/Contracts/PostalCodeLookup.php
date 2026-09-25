<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\Domain\PostalCode;

/**
 * CEP → city/UF/IBGE lookup (IMPLEMENTATION_PLAN §5.6, candidate ADR-029).
 * Interface in the kernel so Customers (layer 1) can derive address data;
 * the implementation (cache → ViaCEP → fallback) and its binding live in Shipping.
 */
interface PostalCodeLookup
{
    /**
     * @return PostalCodeInfo|null null = CEP does not exist
     *
     * @throws PostalCodeLookupUnavailable when the lookup service is down (503)
     */
    public function lookup(PostalCode $cep): ?PostalCodeInfo;
}
