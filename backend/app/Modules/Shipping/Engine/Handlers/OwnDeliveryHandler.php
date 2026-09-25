<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine\Handlers;

use App\Modules\Shipping\Enums\ShippingMethodType;

final class OwnDeliveryHandler extends AbstractRuleBasedHandler
{
    public function type(): ShippingMethodType
    {
        return ShippingMethodType::OwnDelivery;
    }
}
