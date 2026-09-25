<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use Illuminate\Validation\ValidationException;

/** 422 on `new_on_hand` (RN-EST-021): below reserved or unchanged. */
final class InvalidStockAdjustment extends ValidationException
{
    public static function because(string $message, string $field = 'new_on_hand'): self
    {
        /** @var self */
        return self::withMessages([$field => [$message]]);
    }
}
