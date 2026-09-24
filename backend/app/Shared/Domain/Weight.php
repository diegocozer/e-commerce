<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\InvalidValue;
use JsonSerializable;

/** Logistic weight in integer grams (ADR-003). */
final readonly class Weight implements JsonSerializable
{
    private function __construct(private int $grams)
    {
        if ($grams < 0) {
            throw InvalidValue::because('Weight cannot be negative.');
        }
    }

    public static function fromGrams(int $grams): self
    {
        return new self($grams);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** "1.5" / "1,5" kg → 1500 g (string based, no float math). */
    public static function fromKilograms(string $kilograms): self
    {
        $quantity = Quantity::fromString($kilograms);

        return new self($quantity->milli());
    }

    public function grams(): int
    {
        return $this->grams;
    }

    public function add(Weight $other): self
    {
        return new self($this->grams + $other->grams);
    }

    /** Per-unit weight × quantity, rounded up to the gram (SHIPPING.md §2.3: ceil). */
    public function multiplyByQuantity(Quantity $quantity): self
    {
        return new self(Rounding::ceilDiv(Rounding::multiply($this->grams, $quantity->milli()), Quantity::SCALE));
    }

    /** Started kilograms with a minimum of 1 (SHIPPING.md: kg = max(1, ceil(g / 1000))). */
    public function chargeableKilograms(): int
    {
        return max(1, Rounding::ceilDiv($this->grams, 1000));
    }

    public function isZero(): bool
    {
        return $this->grams === 0;
    }

    public function compareTo(Weight $other): int
    {
        return $this->grams <=> $other->grams;
    }

    public function jsonSerialize(): int
    {
        return $this->grams;
    }
}
