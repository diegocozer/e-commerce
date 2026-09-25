<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use App\Modules\Shipping\Enums\FreeShippingReason;
use App\Modules\Shipping\Enums\ShippingMethodType;

/**
 * One shipping option (SHIPPING.md §3). `optionId` is stable across quotes:
 * "{method_id}:{rule_id}" | "{method_id}:{service_code}" | "{method_id}:pickup".
 * `ruleId`/`methodPosition` are internal (never in the public API).
 */
final readonly class ShippingOption
{
    public function __construct(
        public string $optionId,
        public int $methodId,
        public string $methodCode,
        public ShippingMethodType $methodType,
        public string $name,
        public ?string $description,
        public int $priceCents,
        public int $originalPriceCents,
        public bool $isFree,
        public ?FreeShippingReason $freeReason,
        public int $deliveryDaysMin,
        public int $deliveryDaysMax,
        public string $deliveryLabel,
        public ?CarrierInfo $carrier,
        public ?PickupAddress $pickupAddress,
        public ?int $ruleId,
        public int $methodPosition,
    ) {}

    /** Free-shipping coupon applied (SHIPPING.md §6.3): keeps the original price. */
    public function withCouponFreeShipping(): self
    {
        return new self(
            $this->optionId, $this->methodId, $this->methodCode, $this->methodType, $this->name, $this->description,
            0, $this->originalPriceCents, true, FreeShippingReason::Coupon, $this->deliveryDaysMin, $this->deliveryDaysMax,
            $this->deliveryLabel, $this->carrier, $this->pickupAddress, $this->ruleId, $this->methodPosition,
        );
    }

    /** Discount granted by a coupon (orders.shipping_discount_cents). */
    public function couponDiscountCents(): int
    {
        return $this->freeReason === FreeShippingReason::Coupon ? $this->originalPriceCents - $this->priceCents : 0;
    }

    /** Public API shape (API.md §2.6 ShippingOption). */
    public function toPublicArray(): array
    {
        return [
            'option_id' => $this->optionId,
            'method_code' => $this->methodCode,
            'method_type' => $this->methodType->value,
            'name' => $this->name,
            'description' => $this->description,
            'price_cents' => $this->priceCents,
            'original_price_cents' => $this->originalPriceCents,
            'is_free' => $this->isFree,
            'free_reason' => $this->freeReason?->value,
            'delivery_days_min' => $this->deliveryDaysMin,
            'delivery_days_max' => $this->deliveryDaysMax,
            'delivery_label' => $this->deliveryLabel,
            'carrier' => $this->carrier?->toArray(),
            'pickup_address' => $this->pickupAddress?->toArray(),
        ];
    }

    /** Stored shape (shipping_quotes.options element, SHIPPING.md §5.1) — superset with internal ids. */
    public function toStorageArray(): array
    {
        return [
            'id' => $this->optionId,
            'method_id' => $this->methodId,
            'method_code' => $this->methodCode,
            'method_type' => $this->methodType->value,
            'rule_id' => $this->ruleId,
            'carrier_code' => $this->carrier?->code,
            'service_code' => $this->carrier?->serviceCode,
            'name' => $this->name,
            'description' => $this->description,
            'price_cents' => $this->priceCents,
            'original_price_cents' => $this->originalPriceCents,
            'is_free' => $this->isFree,
            'free_reason' => $this->freeReason?->value,
            'delivery_days_min' => $this->deliveryDaysMin,
            'delivery_days_max' => $this->deliveryDaysMax,
            'delivery_label' => $this->deliveryLabel,
            'method_position' => $this->methodPosition,
            'carrier' => $this->carrier?->toArray(),
            'pickup_address' => $this->pickupAddress?->toArray(),
        ];
    }

    /** @param  array<string, mixed>  $d  toStorageArray() output */
    public static function fromStorageArray(array $d): self
    {
        return new self(
            (string) $d['id'],
            (int) $d['method_id'],
            (string) $d['method_code'],
            ShippingMethodType::from((string) $d['method_type']),
            (string) $d['name'],
            $d['description'] ?? null,
            (int) $d['price_cents'],
            (int) $d['original_price_cents'],
            (bool) $d['is_free'],
            isset($d['free_reason']) ? FreeShippingReason::from((string) $d['free_reason']) : null,
            (int) $d['delivery_days_min'],
            (int) $d['delivery_days_max'],
            (string) ($d['delivery_label'] ?? ''),
            CarrierInfo::fromArray($d['carrier'] ?? null),
            PickupAddress::fromArray($d['pickup_address'] ?? null),
            isset($d['rule_id']) ? (int) $d['rule_id'] : null,
            (int) ($d['method_position'] ?? 0),
        );
    }
}
