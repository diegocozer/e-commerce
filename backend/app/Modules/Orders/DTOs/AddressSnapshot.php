<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/**
 * Delivery address snapshot (orders.shipping_*). For pickup orders the address
 * columns are stored as NULL (API.md OrderShippingSnapshot.address = null);
 * `customerAddressId` is kept as reference.
 */
final readonly class AddressSnapshot
{
    public function __construct(
        public ?int $customerAddressId,
        public string $recipientName,
        public ?string $phone,
        public string $postalCode,
        public string $street,
        public string $number,
        public ?string $complement,
        public string $district,
        public string $city,
        public string $state,
        public ?string $cityIbgeCode = null,
        public ?string $reference = null,
    ) {}
}
