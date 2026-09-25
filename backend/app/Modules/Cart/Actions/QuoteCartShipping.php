<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\Exceptions\CartEmpty;
use App\Modules\Cart\Services\CartCalculator;
use App\Modules\Cart\Services\CartLocator;
use App\Modules\Cart\Services\CartView;
use App\Modules\Customers\Contracts\CustomerDirectory;
use App\Modules\Shipping\Contracts\ShippingQuoteService;
use App\Modules\Shipping\Contracts\ShippingRequestFactory;
use App\Modules\Shipping\DTOs\ShippingCustomer;
use App\Modules\Shipping\DTOs\ShippingQuoteData;
use App\Shared\Domain\Money;
use Illuminate\Validation\ValidationException;

/**
 * POST /cart/shipping-quote (API.md §3.B, ARCHITECTURE §4.2): postal code or an
 * address of the logged-in customer; persists the quote (TTL 30 min) and
 * carts.postal_code. A valid free-shipping coupon is considered.
 */
final class QuoteCartShipping
{
    public function __construct(
        private readonly CartLocator $carts,
        private readonly CartCalculator $calculator,
        private readonly CartService $cartService,
        private readonly CartView $view,
        private readonly CustomerDirectory $customers,
        private readonly ShippingRequestFactory $requests,
        private readonly ShippingQuoteService $quotes,
    ) {}

    public function execute(?int $customerId, ?string $token, ?string $postalCode, ?string $addressUuid): ShippingQuoteData
    {
        if ($addressUuid !== null) {
            $address = $customerId !== null ? $this->customers->addressForCustomer($customerId, $addressUuid) : null;
            if ($address === null) {
                throw ValidationException::withMessages(['address_uuid' => ['Endereço inválido.']]);
            }
            $postalCode = $address->postalCode;
        }

        $cart = $this->carts->find($customerId, $token) ?? throw new CartEmpty;
        $snapshot = $this->calculator->snapshot($cart, $customerId);
        $lines = $this->cartService->toShippingLines($snapshot);
        if ($lines === []) {
            throw new CartEmpty;
        }

        $evaluation = $this->view->evaluateCoupon($snapshot);
        $discount = $evaluation?->valid ? $evaluation->discount : Money::zero();
        $request = $this->requests->fromLines(
            $lines,
            (string) $postalCode,
            Money::max(Money::zero(), $snapshot->subtotal->subtract($discount)),
            (bool) ($evaluation?->valid && $evaluation->freeShipping),
            $this->shippingCustomer($customerId),
            $snapshot->cartId,
        );
        $quote = $this->quotes->quoteAndStore($request, $this->cartService->itemConfigs($snapshot));

        $cart->postal_code = $request->destination->postalCode;
        $cart->save();

        return $quote;
    }

    private function shippingCustomer(?int $customerId): ?ShippingCustomer
    {
        $customer = $customerId !== null ? $this->customers->find($customerId) : null;

        return $customer !== null ? new ShippingCustomer($customer->id, $customer->type->value, $customer->companyId) : null;
    }
}
