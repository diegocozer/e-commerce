<?php

declare(strict_types=1);

namespace App\Shared\PostalCode;

/**
 * CEP lookup (SHIPPING.md §4.8, ADR-029). Interface in the kernel so Customers
 * and Shipping can use it; the implementation and binding live in Shipping.
 */
interface PostalCodeLookup
{
    /**
     * @throws PostalCodeNotFoundException CEP does not exist (ViaCEP {"erro": true})
     * @throws PostalCodeLookupException timeout, network, 5xx, invalid JSON
     */
    public function lookup(string $postalCode): PostalCodeInfo;
}
