<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\InvalidValue;
use JsonSerializable;

/**
 * BRL amount in integer cents (ADR-003). Immutable; never uses floats.
 */
final readonly class Money implements JsonSerializable
{
    public const int BASIS_POINTS = 10000;

    private function __construct(private int $cents) {}

    public static function ofCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function add(Money $other): self
    {
        return new self($this->cents + $other->cents);
    }

    /** May become negative; the caller validates. */
    public function subtract(Money $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function multiply(int $factor): self
    {
        return new self(Rounding::multiply($this->cents, $factor));
    }

    /**
     * Line total (ADR-003 / RN-QTD-020): round_half_up(cents × milli / 1000).
     * 1590 × 5.350 m = 8506.5 → 8507.
     */
    public function multiplyByQuantity(Quantity $quantity): self
    {
        return new self(Rounding::halfUpDiv(
            Rounding::multiply($this->cents, $quantity->milli()),
            Quantity::SCALE,
        ));
    }

    /** The given percentage of this amount, half up: 10000 × 1000 bp (10%) → 1000. */
    public function percentage(int $basisPoints): self
    {
        return new self(Rounding::halfUpDiv(Rounding::multiply($this->cents, $basisPoints), self::BASIS_POINTS));
    }

    /** Alias of percentage() kept for ARCHITECTURE.md naming. */
    public function applyBasisPoints(int $basisPoints): self
    {
        return $this->percentage($basisPoints);
    }

    /**
     * Amount after a percentage discount, computed as DATABASE.md DB-07:
     * round_half_up(cents × (10000 − bp) / 10000).
     */
    public function discountByBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > self::BASIS_POINTS) {
            throw InvalidValue::because('Basis points must be between 0 and 10000.');
        }

        return new self(Rounding::halfUpDiv(
            Rounding::multiply($this->cents, self::BASIS_POINTS - $basisPoints),
            self::BASIS_POINTS,
        ));
    }

    /**
     * Splits the amount proportionally to integer weights; rounding leftovers go
     * to the last share (coupon apportionment, DATABASE.md §3.6.3).
     *
     * @param  list<int>  $weights
     * @return list<Money>
     */
    public function allocate(array $weights): array
    {
        $total = array_sum($weights);
        if ($weights === [] || $total <= 0) {
            throw InvalidValue::because('Weights must be non-empty and sum to a positive value.');
        }

        $shares = [];
        $allocated = 0;
        $last = array_key_last($weights);
        foreach ($weights as $index => $weight) {
            if ($weight < 0) {
                throw InvalidValue::because('Weights must be non-negative.');
            }
            $share = $index === $last
                ? $this->cents - $allocated
                : intdiv(Rounding::multiply($this->cents, $weight), $total);
            $allocated += $share;
            $shares[] = new self($share);
        }

        return $shares;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function compareTo(Money $other): int
    {
        return $this->cents <=> $other->cents;
    }

    public function lessThan(Money $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function lessThanOrEqual(Money $other): bool
    {
        return $this->cents <= $other->cents;
    }

    public function greaterThan(Money $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function greaterThanOrEqual(Money $other): bool
    {
        return $this->cents >= $other->cents;
    }

    public static function min(Money $first, Money ...$rest): self
    {
        foreach ($rest as $money) {
            if ($money->cents < $first->cents) {
                $first = $money;
            }
        }

        return $first;
    }

    public static function max(Money $first, Money ...$rest): self
    {
        foreach ($rest as $money) {
            if ($money->cents > $first->cents) {
                $first = $money;
            }
        }

        return $first;
    }

    /** @param  iterable<Money>  $amounts */
    public static function sum(iterable $amounts): self
    {
        $total = 0;
        foreach ($amounts as $money) {
            $total += $money->cents;
        }

        return new self($total);
    }

    /** "R$ 1.234,50" (display only, e-mails/admin exports). */
    public function format(): string
    {
        $abs = abs($this->cents);

        return sprintf(
            '%sR$ %s,%02d',
            $this->cents < 0 ? '-' : '',
            number_format(intdiv($abs, 100), 0, ',', '.'),
            $abs % 100,
        );
    }

    public function jsonSerialize(): int
    {
        return $this->cents;
    }
}
