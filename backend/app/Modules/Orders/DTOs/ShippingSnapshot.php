<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** Chosen shipping option snapshot (orders.shipping_*). `methodType` ∈ pickup|own_delivery|table_rate|carrier. */
final readonly class ShippingSnapshot
{
    public function __construct(
        public string $optionId,
        public string $methodName,
        public string $methodType,
        public ?int $methodId = null,
        public ?int $ruleId = null,
        public ?string $carrierCode = null,
        public ?string $serviceCode = null,
        public ?int $deliveryDaysMin = null,
        public ?int $deliveryDaysMax = null,
        public ?string $quoteUuid = null,
        public int $totalWeightGrams = 0,
        public int $totalVolumeCm3 = 0,
    ) {}

    public function isPickup(): bool
    {
        return $this->methodType === 'pickup';
    }
}
