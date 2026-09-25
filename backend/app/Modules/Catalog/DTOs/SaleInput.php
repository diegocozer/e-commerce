<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

use App\Shared\Domain\Quantity;

/** Customer input: quantity (non SQUARE_METER) or width × height × pieces (SQUARE_METER). */
final readonly class SaleInput
{
    public function __construct(
        public ?Quantity $quantity,
        public ?int $widthMm,
        public ?int $heightMm,
        public ?int $pieces,
    ) {}

    public static function quantity(Quantity $quantity): self
    {
        return new self($quantity, null, null, null);
    }

    public static function dimensions(?int $widthMm, int $heightMm, int $pieces = 1): self
    {
        return new self(null, $widthMm, $heightMm, $pieces);
    }
}
