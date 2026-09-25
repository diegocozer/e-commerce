<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\ShippingQuoteResult;
use App\Modules\Shipping\DTOs\ShippingRequest;

/** Shipping engine (ADR-011, SHIPPING.md §4.3, IMPLEMENTATION_PLAN.md §5.6). Never persists. */
interface ShippingEngine
{
    /** @return list<ShippingOption> sorted (price ↑, max days ↑, min days ↑, method position) */
    public function quote(ShippingRequest $request): array;

    /**
     * Full evaluation (options + unavailable + optional trace).
     *
     * @param  list<int>|null  $onlyMethodIds
     * @param  \DateTimeImmutable|null  $at  evaluation instant (rule validity); default now()
     */
    public function evaluate(ShippingRequest $request, bool $withTrace = false, ?array $onlyMethodIds = null, ?\DateTimeImmutable $at = null): ShippingQuoteResult;
}
