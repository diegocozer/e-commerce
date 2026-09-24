<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\InvalidValue;
use JsonSerializable;
use Stringable;

/**
 * Quantity stored as integer thousandths (ADR-003): 5.5 m → 5500.
 * Parsing is purely string based — floats are never used in arithmetic.
 * Maps to numeric(12,3) in the database.
 */
final readonly class Quantity implements JsonSerializable, Stringable
{
    public const int SCALE = 1000;

    /** numeric(12,3): at most 9 integer digits. */
    private const string PATTERN = '/^(-)?(\d{1,9})(?:[.,](\d{1,3}))?$/';

    private function __construct(private int $milli) {}

    public static function fromMilli(int $milli): self
    {
        return new self($milli);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function ofUnits(int $units): self
    {
        return new self(Rounding::multiply($units, self::SCALE));
    }

    /**
     * Strict decimal parsing: "5", "5.5", "5,5", "5.350", "-1.5".
     * Rejects empty strings, thousands separators, exponent notation and more
     * than 3 decimal places.
     */
    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            throw InvalidValue::because("Invalid quantity [{$value}]: use up to 9 integer digits and 3 decimals.");
        }

        $fraction = str_pad($m[3] ?? '', 3, '0');
        $milli = ((int) $m[2]) * self::SCALE + (int) $fraction;

        return new self($m[1] === '-' ? -$milli : $milli);
    }

    /**
     * Accepts int, numeric string or float (e.g. decoded JSON). A float is
     * converted to its shortest round-trip string representation first, so no
     * float arithmetic is performed (5.35 → "5.35" → 5350).
     */
    public static function fromNumeric(int|float|string $value): self
    {
        if (is_int($value)) {
            return self::ofUnits($value);
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw InvalidValue::because('Invalid quantity: not a finite number.');
            }
            $value = var_export($value, true);
            if (str_ends_with($value, '.0')) {
                $value = substr($value, 0, -2);
            }
        }

        return self::fromString($value);
    }

    /** Alias kept for ARCHITECTURE.md naming. */
    public static function fromDecimal(int|string $value): self
    {
        return self::fromNumeric($value);
    }

    public function milli(): int
    {
        return $this->milli;
    }

    /** Always 3 decimals, "." separator (database format): 5500 → "5.500". */
    public function toDecimalString(): string
    {
        $abs = abs($this->milli);

        return sprintf(
            '%s%d.%03d',
            $this->milli < 0 ? '-' : '',
            intdiv($abs, self::SCALE),
            $abs % self::SCALE,
        );
    }

    /** Without trailing zeros: 5500 → "5.5", 5000 → "5". */
    public function toTrimmedString(): string
    {
        $string = rtrim(rtrim($this->toDecimalString(), '0'), '.');

        return $string === '-0' ? '0' : $string;
    }

    /**
     * pt-BR display: 5500 → "5,50" with 2 decimals. Truncation never happens
     * silently: decimals beyond $decimals are kept when non-zero.
     */
    public function format(int $decimals = 2): string
    {
        [$int, $fraction] = explode('.', $this->toDecimalString());
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, $decimals, '0');
        $sign = str_starts_with($int, '-') ? '-' : '';
        $int = ltrim($int, '-');
        $int = number_format((int) $int, 0, ',', '.');

        return $sign.$int.($fraction !== '' ? ','.$fraction : '');
    }

    /** For output serialization only (JSON number). Never use the result in calculations. */
    public function toNumber(): int|float
    {
        return $this->milli % self::SCALE === 0
            ? intdiv($this->milli, self::SCALE)
            : (float) $this->toDecimalString();
    }

    public function add(Quantity $other): self
    {
        return new self($this->milli + $other->milli);
    }

    public function subtract(Quantity $other): self
    {
        return new self($this->milli - $other->milli);
    }

    public function multiplyBy(int $factor): self
    {
        return new self(Rounding::multiply($this->milli, $factor));
    }

    public function negate(): self
    {
        return new self(-$this->milli);
    }

    public function abs(): self
    {
        return new self(abs($this->milli));
    }

    public function compareTo(Quantity $other): int
    {
        return $this->milli <=> $other->milli;
    }

    public function equals(Quantity $other): bool
    {
        return $this->milli === $other->milli;
    }

    public function greaterThan(Quantity $other): bool
    {
        return $this->milli > $other->milli;
    }

    public function greaterThanOrEqual(Quantity $other): bool
    {
        return $this->milli >= $other->milli;
    }

    public function lessThan(Quantity $other): bool
    {
        return $this->milli < $other->milli;
    }

    public function lessThanOrEqual(Quantity $other): bool
    {
        return $this->milli <= $other->milli;
    }

    public function isZero(): bool
    {
        return $this->milli === 0;
    }

    public function isPositive(): bool
    {
        return $this->milli > 0;
    }

    public function isNegative(): bool
    {
        return $this->milli < 0;
    }

    public function isInteger(): bool
    {
        return $this->milli % self::SCALE === 0;
    }

    /** RN-QTD-010: valid when multiple of the step counted from zero (5050 % 100 ≠ 0). */
    public function isMultipleOf(Quantity $step): bool
    {
        if (! $step->isPositive()) {
            throw InvalidValue::because('Step must be positive.');
        }

        return $this->milli % $step->milli === 0;
    }

    public function floorToMultipleOf(Quantity $step): self
    {
        if (! $step->isPositive()) {
            throw InvalidValue::because('Step must be positive.');
        }

        return new self(intdiv($this->milli, $step->milli) * $step->milli
            - ($this->milli < 0 && $this->milli % $step->milli !== 0 ? $step->milli : 0));
    }

    public function ceilToMultipleOf(Quantity $step): self
    {
        $floor = $this->floorToMultipleOf($step);

        return $floor->equals($this) ? $floor : new self($floor->milli + $step->milli);
    }

    public static function max(Quantity $first, Quantity ...$rest): self
    {
        foreach ($rest as $q) {
            if ($q->milli > $first->milli) {
                $first = $q;
            }
        }

        return $first;
    }

    public static function min(Quantity $first, Quantity ...$rest): self
    {
        foreach ($rest as $q) {
            if ($q->milli < $first->milli) {
                $first = $q;
            }
        }

        return $first;
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
