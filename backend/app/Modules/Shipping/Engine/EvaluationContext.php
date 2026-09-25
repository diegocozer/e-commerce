<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine;

use App\Modules\Shipping\Domain\Config\ShippingConfigSnapshot;
use App\Modules\Shipping\DTOs\CarrierQuote;
use App\Modules\Shipping\Enums\UnavailableReason;

/** Per-evaluation state: config, instant, carrier memo (1 call per carrier) and time budget. */
final class EvaluationContext
{
    /** @var array<int, CarrierQuote|UnavailableReason> */
    public array $carrierMemo = [];

    public function __construct(
        public readonly ShippingConfigSnapshot $config,
        public readonly \DateTimeImmutable $now,
        public readonly int $carrierBudgetStartMs,
    ) {}
}
