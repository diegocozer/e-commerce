<?php

declare(strict_types=1);

namespace App\Shared\PostalCode;

/** Result of PostalCodeLookup (SHIPPING.md §4.8, ADR-029). */
final readonly class PostalCodeInfo
{
    public function __construct(
        public string $postalCode,
        public ?string $street,
        public ?string $district,
        public string $city,
        public string $state,
        public string $cityIbgeCode,
    ) {}
}
