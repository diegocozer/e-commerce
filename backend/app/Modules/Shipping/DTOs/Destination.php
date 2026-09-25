<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** Resolved destination of a quote (SHIPPING.md §2.5). */
final readonly class Destination
{
    public function __construct(
        public string $postalCode,       // exactly 8 digits
        public ?string $cityIbgeCode,    // 7 digits; null when the lookup failed
        public ?string $city,
        public ?string $state,           // UF; may come from the static CEP range fallback
        public bool $resolved,           // PostalCodeLookup answered successfully
        public ?string $stateSource,     // 'lookup' | 'cep_range' | null
    ) {}

    /** First 5 digits, the only part of the CEP that may be logged. */
    public function postalPrefix(): string
    {
        return substr($this->postalCode, 0, 5);
    }

    /** @return array{postal_code: string, city: string|null, state: string|null} */
    public function toPublicArray(): array
    {
        return ['postal_code' => $this->postalCode, 'city' => $this->city, 'state' => $this->state];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'city_ibge_code' => $this->cityIbgeCode,
            'state' => $this->state,
            'resolved' => $this->resolved,
            'state_source' => $this->stateSource,
        ];
    }
}
