<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Exceptions;

use App\Shared\Exceptions\DomainException;
use App\Shared\Exceptions\ErrorCode;

/** 409 resource_in_use with `blockers` (API.md §1.6). */
final class ResourceInUse extends DomainException
{
    /** @param  list<array{type: string, id: int, label: string}>  $blockers */
    public function __construct(string $message, array $blockers)
    {
        parent::__construct($message, ErrorCode::ResourceInUse->value, 409, ['blockers' => $blockers]);
    }
}
