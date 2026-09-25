<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

use App\Modules\Shipping\DTOs\CartLineLogisticsInput;
use App\Modules\Shipping\DTOs\ShippingCustomer;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Shared\Domain\Money;
use Illuminate\Validation\ValidationException;

interface ShippingRequestFactory
{
    /**
     * Normalizes the CEP (invalid → ValidationException 422 `postal_code` "CEP inválido."),
     * resolves the destination (lookup + CEP→UF fallback, never fails on lookup errors)
     * and computes CartLogistics (SHIPPING.md §2.3/2.4). Does not know Cart.
     *
     * @param  list<CartLineLogisticsInput>  $lines
     *
     * @throws ValidationException
     */
    public function fromLines(
        array $lines,
        string $rawPostalCode,
        Money $subtotalAfterDiscounts,
        bool $couponFreeShipping,
        ?ShippingCustomer $customer = null,
        ?int $cartId = null,
    ): ShippingRequest;
}
