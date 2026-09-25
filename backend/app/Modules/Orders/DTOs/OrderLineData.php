<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use App\Modules\Pricing\Enums\PriceSource;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;

/**
 * One fully computed order line (DATABASE.md §3.6.3). `quantity` is null for
 * SQUARE_METER (dimensions + pieces instead). `stockQuantity` is what is
 * reserved/committed (ADR-019). `discount` = coupon apportionment of the line;
 * when every line leaves it null, OrderPlacement apportions the order discount
 * proportionally (remainder on the last line).
 */
final readonly class OrderLineData
{
    public function __construct(
        public int $variantId,
        public int $productId,
        public string $productName,
        public string $variantName,
        public string $sku,
        public SaleUnit $saleUnit,
        public ?Quantity $quantity,
        public ?int $widthMm,
        public ?int $heightMm,
        public ?int $pieces,
        public Quantity $billableQuantity,
        public Quantity $stockQuantity,
        public Money $baseUnitPrice,
        public Money $unitPrice,
        public PriceSource $priceSource,
        public Money $subtotal,
        public int $weightGrams,
        public ?int $priceListId = null,
        public ?int $promotionId = null,
        public ?Money $discount = null,
    ) {}
}
