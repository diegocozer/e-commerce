<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/**
 * Engine input (SHIPPING.md §2.6). Build it with
 * Contracts\ShippingRequestFactory::make() (normalizes/resolves the CEP and
 * computes the logistics).
 */
final readonly class ShippingRequest
{
    /**
     * @param  list<array{variant_id: int, quantity_milli: int|null, width_mm: int|null, height_mm: int|null, pieces: int|null}>  $itemConfigs
     *                                                                                                                                 what the customer chose (request hash, SHIPPING §4.9)
     */
    public function __construct(
        public Destination $destination,
        public CartLogistics $logistics,
        public int $subtotalCents,          // items subtotal AFTER discounts, without shipping
        public bool $couponFreeShipping,    // valid free-shipping coupon applied to the cart
        public ?ShippingCustomer $customer = null,
        public ?int $cartId = null,         // carts.id (null for product estimate / admin simulation)
        public ?string $requestId = null,   // X-Request-Id
        public array $itemConfigs = [],
    ) {}
}
