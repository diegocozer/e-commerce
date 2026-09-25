<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** Postal code lookup result (SHIPPING.md §4.8 / API.md §2.6). */
final readonly class PostalCodeInfo
{
    public function __construct(
        public string $postalCode,
        public ?string $street,
        public ?string $district,
        public string $city,
        public string $state,
        public string $cityIbgeCode,
        public string $source = 'viacep',   // viacep | cache
    ) {}

    public function withSource(string $source): self
    {
        return new self($this->postalCode, $this->street, $this->district, $this->city, $this->state, $this->cityIbgeCode, $source);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'postal_code' => $this->postalCode, 'street' => $this->street, 'district' => $this->district,
            'city' => $this->city, 'state' => $this->state, 'city_ibge_code' => $this->cityIbgeCode, 'source' => $this->source,
        ];
    }
}
