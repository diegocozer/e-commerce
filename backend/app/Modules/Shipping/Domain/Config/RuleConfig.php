<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Config;

use App\Modules\Shipping\Enums\ShippingPriceType;

/** Immutable view of a shipping rule. Limits are inclusive; validUntil is exclusive. */
final readonly class RuleConfig
{
    public function __construct(
        public int $id,
        public int $methodId,
        public ?int $zoneId,
        public string $name,
        public int $priority,
        public ?int $minWeightGrams,
        public ?int $maxWeightGrams,
        public ?int $minSubtotalCents,
        public ?int $maxSubtotalCents,
        public ?int $minVolumeCm3,
        public ?int $maxVolumeCm3,
        public ?int $maxPackageLengthMm,
        public ShippingPriceType $priceType,
        public int $priceCents,
        public int $perKgCents,
        public int $percentageBp,
        public ?int $minPriceCents,
        public ?int $maxPriceCents,
        public ?int $deliveryDaysMin,
        public ?int $deliveryDaysMax,
        public bool $isActive,
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validUntil,
    ) {}

    public function isValidAt(\DateTimeImmutable $now): bool
    {
        return $this->isActive
            && ($this->validFrom === null || $now >= $this->validFrom)
            && ($this->validUntil === null || $now < $this->validUntil);
    }

    /** No condition at all (used by the rule_never_reachable warning). */
    public function isUnconditional(): bool
    {
        return $this->minWeightGrams === null && $this->maxWeightGrams === null && $this->minSubtotalCents === null
            && $this->maxSubtotalCents === null && $this->minVolumeCm3 === null && $this->maxVolumeCm3 === null
            && $this->maxPackageLengthMm === null && $this->validFrom === null && $this->validUntil === null;
    }
}
