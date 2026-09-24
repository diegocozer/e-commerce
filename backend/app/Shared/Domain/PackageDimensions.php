<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\InvalidValue;

/**
 * Logistic package dimensions (ADR-003: centimetres with 1 decimal in the
 * database/API, integer millimetres in the domain).
 */
final readonly class PackageDimensions
{
    private const string PATTERN = '/^(\d{1,6})(?:[.,](\d))?$/';

    public function __construct(public int $lengthMm, public int $widthMm, public int $heightMm)
    {
        if ($lengthMm <= 0 || $widthMm <= 0 || $heightMm <= 0) {
            throw InvalidValue::because('Package dimensions must be positive.');
        }
    }

    public static function fromCentimeters(string $length, string $width, string $height): self
    {
        return new self(self::cmToMm($length), self::cmToMm($width), self::cmToMm($height));
    }

    /** Volume in cm³, rounded up (never under-estimate freight). */
    public function volumeCm3(): int
    {
        return Rounding::ceilDiv(
            Rounding::multiply(Rounding::multiply($this->lengthMm, $this->widthMm), $this->heightMm),
            1000,
        );
    }

    public function longestSideMm(): int
    {
        return max($this->lengthMm, $this->widthMm, $this->heightMm);
    }

    private static function cmToMm(string $value): int
    {
        $value = trim($value);
        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            throw InvalidValue::because("Invalid centimetre value [{$value}].");
        }

        return ((int) $m[1]) * 10 + (int) ($m[2] ?? 0);
    }
}
