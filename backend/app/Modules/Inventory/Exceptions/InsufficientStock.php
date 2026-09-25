<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\DomainException;

/**
 * 409 insufficient_stock (API.md §1.6) with `items: StockIssue[]`
 * ({cart_item_id: null, variant_id, sku, product_name, requested_quantity, available_quantity}).
 * Callers that know cart item ids may rebuild the items with withItems().
 */
final class InsufficientStock extends DomainException
{
    protected string $errorCode = 'insufficient_stock';

    protected int $httpStatus = 409;

    /**
     * @param  list<array{cart_item_id: int|null, variant_id: int, sku: string, product_name: string, requested_quantity: int|float, available_quantity: int|float}>  $items
     */
    public static function forItems(array $items): self
    {
        return new self('Estoque insuficiente para um ou mais itens.', details: ['items' => $items]);
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return $this->details()['items'] ?? [];
    }

    /** @param  list<array<string, mixed>>  $items */
    public function withItems(array $items): self
    {
        return new self($this->getMessage(), details: ['items' => $items], previous: $this);
    }
}
