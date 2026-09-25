<?php

declare(strict_types=1);

namespace App\Modules\Customers\DTOs;

final readonly class AddressData
{
    public function __construct(
        public int $id,
        public string $uuid,
        public int $customerId,
        public ?string $label,
        public string $recipientName,
        public ?string $phone,
        public string $postalCode,
        public string $street,
        public string $number,
        public ?string $complement,
        public string $district,
        public string $city,
        public string $state,
        public ?string $cityIbgeCode,
        public ?string $reference,
        public bool $isDefault,
    ) {}
}
