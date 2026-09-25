<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine;

use App\Modules\Shipping\Domain\Config\MethodConfig;
use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\UnavailableMethod;
use App\Modules\Shipping\Enums\UnavailableReason;

final readonly class MethodResult
{
    private function __construct(public ?ShippingOption $option, public ?UnavailableMethod $unavailable) {}

    public static function option(ShippingOption $option): self
    {
        return new self($option, null);
    }

    public static function unavailable(MethodConfig $method, UnavailableReason $reason, ?string $detail = null): self
    {
        return new self(null, new UnavailableMethod($method->id, $method->code, $reason, $detail));
    }
}
