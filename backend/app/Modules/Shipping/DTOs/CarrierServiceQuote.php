<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

final readonly class CarrierServiceQuote
{
    public function __construct(
        public string $serviceCode,
        public string $serviceName,
        public ?int $priceCents,         // null when the service returned an error
        public ?int $deliveryDaysMin,
        public ?int $deliveryDaysMax,
        public ?string $error = null,
    ) {}
}
