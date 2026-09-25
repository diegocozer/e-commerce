<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Catalog\Exceptions\InvalidSaleQuantity;
use Illuminate\Validation\ValidationException;

/**
 * Normalizes Catalog's InvalidSaleQuantity (422 with field and optional
 * `details.<field>.suggestions`) into the Cart's 422 body / line warning.
 */
final class InvalidQuantityDetails
{
    /** @return array{field: string, message: string, suggestions: list<int|float>} */
    public static function from(InvalidSaleQuantity $e): array
    {
        $field = method_exists($e, 'field') ? (string) $e->field() : 'quantity';
        $suggestions = [];
        if (method_exists($e, 'suggestions')) {
            $suggestions = (array) $e->suggestions();
        } elseif (method_exists($e, 'details')) {
            $details = (array) $e->details();
            $suggestions = (array) ($details['details'][$field]['suggestions'] ?? $details[$field]['suggestions'] ?? $details['suggestions'] ?? []);
        }

        return [
            'field' => $field,
            'message' => $e->getMessage(),
            'suggestions' => array_values(array_map(
                static fn (mixed $s): int|float => is_object($s) && method_exists($s, 'toNumber') ? $s->toNumber() : (is_numeric($s) ? $s + 0 : 0),
                $suggestions,
            )),
        ];
    }

    /** 422 in the API.md §1.6 format (`errors` + `details.<field>.suggestions`). */
    public static function toValidationException(InvalidSaleQuantity $e, string $prefix = ''): ValidationException
    {
        $d = self::from($e);
        $field = $prefix.$d['field'];
        $exception = ValidationException::withMessages([$field => [$d['message']]]);
        if ($d['suggestions'] !== []) {
            $exception->response = response()->json([
                'message' => $d['message'],
                'errors' => [$field => [$d['message']]],
                'details' => [$field => ['suggestions' => $d['suggestions']]],
            ], 422);
        }

        return $exception;
    }
}
