<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\ShippingQuoteData;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\DTOs\ValidatedShippingSelection;
use App\Modules\Shipping\Exceptions\ShippingConflict;

/** Application service around the engine (SHIPPING.md §6.5/§7, IMPLEMENTATION_PLAN.md §5.6). */
interface ShippingQuoteService
{
    /**
     * Store: evaluates and persists in shipping_quotes (TTL 30 min). Reuses a valid
     * identical quote (same cart + hash + unchanged shipping config). Requires $request->cartId.
     *
     * @param  list<array{variant_id: int, quantity_milli: int|null, width_mm: int|null, height_mm: int|null, pieces: int|null}>  $cartItemConfigs
     */
    public function quoteAndStore(ShippingRequest $request, array $cartItemConfigs): ShippingQuoteData;

    /** Product estimate: evaluates without persisting (quote_id null). */
    public function estimate(ShippingRequest $request): ShippingQuoteData;

    /**
     * Checkout (SHIPPING.md §7). Returns the option with the RECALCULATED price.
     *
     * @param  list<array{variant_id: int, quantity_milli: int|null, width_mm: int|null, height_mm: int|null, pieces: int|null}>  $cartItemConfigs
     *
     * @throws ShippingConflict 409 shipping_* with ->newQuote
     */
    public function validateForCheckout(string $quoteUuid, string $optionId, ShippingRequest $current,
        array $cartItemConfigs, int $cartId, int $customerId): ShippingOption;

    /**
     * Same as validateForCheckout() but also returns the quote uuid to store in
     * orders.shipping_quote_uuid (a new quote when the choice was silently accepted).
     *
     * @param  list<array{variant_id: int, quantity_milli: int|null, width_mm: int|null, height_mm: int|null, pieces: int|null}>  $cartItemConfigs
     *
     * @throws ShippingConflict
     */
    public function validateSelectionForCheckout(string $quoteUuid, string $optionId, ShippingRequest $current,
        array $cartItemConfigs, int $cartId, int $customerId): ValidatedShippingSelection;

    public function find(string $quoteUuid): ?ShippingQuoteData;
}
