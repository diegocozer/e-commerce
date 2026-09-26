<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Shared\Domain\Quantity;

/**
 * Current offer (catalog card data + price for the customer + availability) for
 * variants bought before — used by Checkout's GET /me/reorder-suggestions
 * (API.md §3.D, ADR-035), which cannot depend on Catalog directly.
 */
interface ReorderOffers
{
    /**
     * Unsellable and out-of-stock variants are omitted.
     *
     * @param  array<int, Quantity>  $billableByVariant  variant_id => billable quantity used to resolve the price (tiers)
     * @return array<int, array{product: array<string, mixed>, variant: array{id: int, sku: string, name: string}, current_unit_price_cents: int, price_source: string, availability_status: string}>
     *                                                                                                                                                                                                keyed by variant_id, in the input order
     */
    public function offers(array $billableByVariant, ?int $customerId): array;
}
