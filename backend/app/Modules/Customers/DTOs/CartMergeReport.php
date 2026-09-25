<?php

declare(strict_types=1);

namespace App\Modules\Customers\DTOs;

use JsonSerializable;

/** API.md §2.5 CartMergeReport (built by Cart, returned by login/registration). */
final readonly class CartMergeReport implements JsonSerializable
{
    /**
     * @param  list<array{variant_id:int, sku:string, product_name:string, previous_quantity:int|float, quantity:int|float, reason:string}>  $adjustments
     * @param  list<array{variant_id:int, sku:string, product_name:string, reason:string}>  $dropped
     * @param  array{code:string, kept:bool, reason_code:?string}|null  $coupon
     */
    public function __construct(
        public bool $merged,
        public int $linesAdded = 0,
        public int $linesCombined = 0,
        public array $adjustments = [],
        public array $dropped = [],
        public ?array $coupon = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'merged' => $this->merged,
            'lines_added' => $this->linesAdded,
            'lines_combined' => $this->linesCombined,
            'adjustments' => $this->adjustments,
            'dropped' => $this->dropped,
            'coupon' => $this->coupon,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
