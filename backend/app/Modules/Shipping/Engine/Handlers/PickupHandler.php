<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine\Handlers;

use App\Modules\Shipping\Domain\Config\MethodConfig;
use App\Modules\Shipping\Domain\Trace\MethodTrace;
use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Engine\DeliveryLabelFormatter;
use App\Modules\Shipping\Engine\EvaluationContext;
use App\Modules\Shipping\Engine\MethodResult;
use App\Modules\Shipping\Engine\ZoneMatchSet;
use App\Modules\Shipping\Enums\ShippingMethodType;

/** Store pickup: always available, price 0, address from pickup_* columns (SHIPPING.md §9). */
final class PickupHandler implements ShippingMethodHandlerInterface
{
    public function __construct(private readonly DeliveryLabelFormatter $labels) {}

    public function type(): ShippingMethodType
    {
        return ShippingMethodType::Pickup;
    }

    public function handle(MethodConfig $method, ShippingRequest $request, ZoneMatchSet $zoneMatches, ?MethodTrace $trace, EvaluationContext $context): MethodResult
    {
        if ($trace !== null) {
            $trace->status = 'option';
            $trace->priceBreakdown = ['price_type' => 'pickup', 'final_cents' => 0];
        }

        return MethodResult::option(new ShippingOption(
            optionId: $method->id.':pickup',
            methodId: $method->id,
            methodCode: $method->code,
            methodType: $method->type,
            name: $method->name,
            description: $method->description,
            priceCents: 0,
            originalPriceCents: 0,
            isFree: true,
            freeReason: null,
            deliveryDaysMin: $method->deliveryDaysMin,
            deliveryDaysMax: $method->deliveryDaysMax,
            deliveryLabel: $this->labels->pickup($method->deliveryDaysMin, $method->deliveryDaysMax),
            carrier: null,
            pickupAddress: $method->pickupAddress,
            ruleId: null,
            methodPosition: $method->position,
        ));
    }
}
