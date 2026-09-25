<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

final readonly class PickupAddress
{
    public function __construct(
        public string $street,
        public ?string $number,
        public ?string $complement,
        public ?string $district,
        public string $city,
        public string $state,
        public string $postalCode,
        public ?string $openingHours,
        public ?string $instructions,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'street' => $this->street, 'number' => $this->number, 'complement' => $this->complement,
            'district' => $this->district, 'city' => $this->city, 'state' => $this->state,
            'postal_code' => $this->postalCode, 'opening_hours' => $this->openingHours, 'instructions' => $this->instructions,
        ];
    }

    /** @param  array<string, mixed>|null  $d */
    public static function fromArray(?array $d): ?self
    {
        return $d === null ? null : new self(
            (string) $d['street'], $d['number'] ?? null, $d['complement'] ?? null, $d['district'] ?? null,
            (string) $d['city'], (string) $d['state'], (string) $d['postal_code'], $d['opening_hours'] ?? null, $d['instructions'] ?? null,
        );
    }
}
