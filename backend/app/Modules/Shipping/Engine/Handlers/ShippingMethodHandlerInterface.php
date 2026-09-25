<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine\Handlers;

use App\Modules\Shipping\Domain\Config\MethodConfig;
use App\Modules\Shipping\Domain\Trace\MethodTrace;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Engine\EvaluationContext;
use App\Modules\Shipping\Engine\MethodResult;
use App\Modules\Shipping\Engine\ZoneMatchSet;
use App\Modules\Shipping\Enums\ShippingMethodType;

interface ShippingMethodHandlerInterface
{
    public function type(): ShippingMethodType;

    /** Never throws to the engine: errors become MethodResult::unavailable(). */
    public function handle(MethodConfig $method, ShippingRequest $request, ZoneMatchSet $zoneMatches, ?MethodTrace $trace, EvaluationContext $context): MethodResult;
}
