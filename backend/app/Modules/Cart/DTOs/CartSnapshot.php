<?php

declare(strict_types=1);

namespace App\Modules\Cart\DTOs;

use App\Modules\Cart\Enums\CartLineStatus;
use App\Shared\Domain\Money;
use App\Shared\Domain\Weight;
use Carbon\CarbonImmutable;

/** Recalculated cart (ARCHITECTURE.md §2.4). `hash` = sha256 of items + quantities + dimensions. */
final readonly class CartSnapshot
{
    /** @param list<CartLine> $lines */
    public function __construct(
        public int $cartId,
        public ?int $customerId,
        public array $lines,
        public Money $subtotal,
        public ?string $couponCode,
        public Weight $totalWeight,
        public string $hash,
        public string $token,
        public ?int $couponId = null,
        public ?string $postalCode = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** @return list<CartLine> */
    public function priceableLines(): array
    {
        return array_values(array_filter($this->lines, static fn (CartLine $l): bool => $l->isPriceable()));
    }

    /** @return list<CartLine> */
    public function linesWithStatus(CartLineStatus ...$statuses): array
    {
        return array_values(array_filter($this->lines, static fn (CartLine $l): bool => in_array($l->status, $statuses, true)));
    }

    public function hasPriceChanges(): bool
    {
        foreach ($this->lines as $line) {
            foreach ($line->warnings as $w) {
                if (($w['code'] ?? null) === 'price_changed') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<int> */
    public function variantIds(): array
    {
        return array_values(array_unique(array_map(static fn (CartLine $l): int => $l->variantId, $this->lines)));
    }
}
