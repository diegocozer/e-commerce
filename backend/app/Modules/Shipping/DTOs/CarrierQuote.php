<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** All services quoted by one carrier (SHIPPING.md §4.5 "ShippingQuote", renamed to avoid clashing with the model). */
final readonly class CarrierQuote
{
    /** @param  list<CarrierServiceQuote>  $services */
    public function __construct(public string $carrierCode, public array $services) {}

    public function service(string $serviceCode): ?CarrierServiceQuote
    {
        foreach ($this->services as $service) {
            if ($service->serviceCode === $serviceCode) {
                return $service;
            }
        }

        return null;
    }
}
