<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/** Gateway payment unknown locally (webhook before commit — Q-16): the job fails and is retried. */
final class PaymentNotFound extends RuntimeException
{
    public static function forExternal(string $provider, string $externalId): self
    {
        return new self("Payment {$provider}:{$externalId} not found locally.");
    }
}
