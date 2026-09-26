<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Cart\DTOs\CartSnapshot;
use App\Modules\Customers\DTOs\AddressData;
use App\Modules\Customers\DTOs\CustomerData;
use App\Modules\Pricing\DTOs\CouponEvaluation;
use App\Modules\Shipping\DTOs\ValidatedShippingSelection;
use App\Shared\Domain\Money;

/** Result of CheckoutCalculator: server-side values + the API.md CheckoutSummary. */
final readonly class CheckoutComputation
{
    /** @param array<string, mixed> $summary */
    public function __construct(
        public ?CartSnapshot $snapshot,
        public AddressData $address,
        public ?CustomerData $customer,
        public ?CouponEvaluation $coupon,
        public ?ValidatedShippingSelection $shipping,
        public Money $subtotal,
        public Money $discount,
        public Money $shippingGross,
        public Money $total,
        public array $summary,
    ) {}
}
