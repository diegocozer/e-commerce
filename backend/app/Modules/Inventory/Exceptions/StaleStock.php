<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\DomainException;

/** 409 stale_resource: the operator saw a different on_hand (API.md §3.G.8). */
final class StaleStock extends DomainException
{
    protected string $errorCode = 'stale_resource';

    protected int $httpStatus = 409;

    public static function currentIs(string $currentOnHand): self
    {
        return new self('O estoque mudou enquanto você editava.', details: ['current_on_hand' => (float) $currentOnHand]);
    }
}
