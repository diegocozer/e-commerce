<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/** Result of PostalCodeLookup (SHIPPING.md §4.8 / ARCHITECTURE.md §7.3). */
final readonly class PostalCodeInfo
{
    public function __construct(
        public string $postalCode,
        public ?string $street,
        public ?string $district,
        public string $city,
        public string $state,
        public ?string $cityIbgeCode,
        public string $source = 'viacep',
    ) {}
}
