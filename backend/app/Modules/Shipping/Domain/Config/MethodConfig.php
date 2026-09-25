<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Config;

use App\Modules\Shipping\DTOs\PickupAddress;
use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Enums\WeightBasis;

/** Immutable view of an active shipping method (cached in the config snapshot). */
final readonly class MethodConfig
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $description,
        public ShippingMethodType $type,
        public ?int $carrierId,
        public ?string $carrierServiceCode,
        public int $deliveryDaysMin,
        public int $deliveryDaysMax,
        public int $handlingDays,
        public WeightBasis $weightBasis,
        public ?int $cubicDivisor,
        public bool $acceptsFreeShippingCoupon,
        public int $position,
        public ?PickupAddress $pickupAddress,
    ) {}
}
