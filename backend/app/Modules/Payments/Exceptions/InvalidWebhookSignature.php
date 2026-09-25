<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/** Missing/invalid signature or timestamp outside the tolerance window (401, empty body). */
final class InvalidWebhookSignature extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
