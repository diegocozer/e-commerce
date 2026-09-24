<?php

declare(strict_types=1);

namespace App\Shared\Casts;

use App\Shared\Domain\Quantity;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * numeric(12,3) ↔ Quantity (integer thousandths). Accepts Quantity, int or a
 * decimal string when setting; floats are rejected to avoid silent precision
 * loss.
 *
 * @implements CastsAttributes<Quantity|null, Quantity|int|string|null>
 */
final class QuantityCast implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Quantity
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Quantity ? $value : Quantity::fromNumeric(is_int($value) ? $value : (string) $value);
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof Quantity => $value->toDecimalString(),
            is_int($value), is_string($value) => Quantity::fromNumeric($value)->toDecimalString(),
            default => throw new InvalidArgumentException("Attribute [{$key}] expects a Quantity, int or decimal string."),
        };
    }
}
