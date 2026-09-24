<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\InvalidValue;
use JsonSerializable;
use Stringable;

/** Brazilian CEP stored as 8 digits (char(8)). */
final readonly class PostalCode implements JsonSerializable, Stringable
{
    private function __construct(private string $value) {}

    public static function fromString(string $raw): self
    {
        $normalized = self::normalize($raw);
        if ($normalized === null) {
            throw InvalidValue::because('Invalid postal code.');
        }

        return new self($normalized);
    }

    /**
     * "89010-001", "89.010-001", " 89010001 " → "89010001"; null when it is not
     * a CEP (wrong length, letters, all zeros).
     */
    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{2}\.?\d{3}-?\d{3}$/', $raw) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';

        return $digits === '00000000' ? null : $digits;
    }

    public static function isValid(string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    public function value(): string
    {
        return $this->value;
    }

    /** "89010-001" */
    public function formatted(): string
    {
        return substr($this->value, 0, 5).'-'.substr($this->value, 5);
    }

    public function equals(PostalCode $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
