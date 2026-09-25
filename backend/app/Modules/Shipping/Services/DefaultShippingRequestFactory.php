<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Contracts\ShippingRequestFactory;
use App\Modules\Shipping\Domain\Logistics\CartLogisticsCalculator;
use App\Modules\Shipping\DTOs\ShippingCustomer;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\PostalCode\DestinationResolver;
use App\Modules\Shipping\PostalCode\PostalCodeNormalizer;
use App\Shared\Domain\Money;
use App\Shared\Http\Middleware\RequestId;
use Illuminate\Support\Facades\Log;

final class DefaultShippingRequestFactory implements ShippingRequestFactory
{
    public function __construct(
        private readonly DestinationResolver $destinations,
        private readonly CartLogisticsCalculator $calculator,
    ) {}

    public function fromLines(array $lines, string $rawPostalCode, Money $subtotalAfterDiscounts, bool $couponFreeShipping, ?ShippingCustomer $customer = null, ?int $cartId = null): ShippingRequest
    {
        $cep = PostalCodeNormalizer::normalize($rawPostalCode);
        $logistics = $this->calculator->calculate($lines);
        if ($logistics->missingData) {
            Log::channel('shipping')->warning('shipping.logistics.missing_data', [
                'variant_ids' => $logistics->variantsMissingData, 'cep_prefix' => substr($cep, 0, 5), 'request_id' => RequestId::current(),
            ]);
        }

        return new ShippingRequest(
            destination: $this->destinations->resolve($cep),
            logistics: $logistics,
            subtotalCents: max(0, $subtotalAfterDiscounts->cents()),
            couponFreeShipping: $couponFreeShipping,
            customer: $customer,
            cartId: $cartId,
            requestId: RequestId::current(),
        );
    }
}
