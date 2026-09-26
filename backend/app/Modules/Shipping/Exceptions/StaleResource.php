<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Exceptions;

use App\Shared\Exceptions\DomainException;
use App\Shared\Exceptions\ErrorCode;

/** 409 stale_resource (expected_updated_at differs, API.md §1.9). */
final class StaleResource extends DomainException
{
    public function __construct(?string $currentUpdatedAt)
    {
        parent::__construct('Este registro foi alterado por outra pessoa. Recarregue e tente novamente.', ErrorCode::StaleResource->value, 409, [
            'current_updated_at' => $currentUpdatedAt,
        ]);
    }
}
