<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/**
 * Engine input (SHIPPING.md §2.6). Build it with
 * Contracts\ShippingRequestFactory::fromLines() (normalizes/resolves the CEP
 * and computes the logistics).
 */
final readonly class ShippingRequest
{
    public function __construct(
        public Destination $destination,
        public CartLogistics $logistics,
        public int $subtotalCents,          // items subtotal AFTER discounts, without shipping
        public bool $couponFreeShipping,    // valid free-shipping coupon applied to the cart
        public ?ShippingCustomer $customer = null,
        public ?int $cartId = null,         // carts.id (null for product estimate / admin simulation)
        public ?string $requestId = null,   // X-Request-Id (ADR-013)
    ) {}
}
