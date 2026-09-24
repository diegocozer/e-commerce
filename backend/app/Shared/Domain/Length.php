<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\InvalidValue;
use JsonSerializable;

/**
 * Length of sellable material in integer millimetres (ADR-003). The API
 * exposes metres with up to 3 decimals ("1.220" / "1,22"); conversion is
 * string based.
 */
final readonly class Length implements JsonSerializable
{
    private const string PATTERN = '/^(\d{1,6})(?:[.,](\d{1,3}))?$/';

    private function __construct(private int $millimeters) {}

    public static function fromMillimeters(int $millimeters): self
    {
        if ($millimeters <= 0) {
            throw InvalidValue::because('Length must be positive.');
        }

        return new self($millimeters);
    }

    /** "1.22" / "1,220" / "3" metres → mm. Rejects more than 3 decimals. */
    public static function fromMeters(string $meters): self
    {
        $meters = trim($meters);
        if (preg_match(self::PATTERN, $meters, $m) !== 1) {
            throw InvalidValue::because("Invalid length [{$meters}]: use metres with up to 3 decimals.");
        }

        return self::fromMillimeters(((int) $m[1]) * 1000 + (int) str_pad($m[2] ?? '', 3, '0'));
    }

    public function millimeters(): int
    {
        return $this->millimeters;
    }

    /** "1.220" */
    public function toMetersString(): string
    {
        return sprintf('%d.%03d', intdiv($this->millimeters, 1000), $this->millimeters % 1000);
    }

    /** RN-QTD-034: dimensions accepted with 1 cm precision in the MVP. */
    public function isWholeCentimeters(): bool
    {
        return $this->millimeters % 10 === 0;
    }

    public function equals(Length $other): bool
    {
        return $this->millimeters === $other->millimeters;
    }

    public function compareTo(Length $other): int
    {
        return $this->millimeters <=> $other->millimeters;
    }

    public function jsonSerialize(): string
    {
        return $this->toMetersString();
    }
}
