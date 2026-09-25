<?php

declare(strict_types=1);

namespace App\Modules\Customers\Events;

use App\Modules\Customers\Enums\CustomerType;
use Carbon\CarbonImmutable;

final readonly class CustomerRegistered
{
    public function __construct(public int $customerId, public CustomerType $type, public CarbonImmutable $occurredAt) {}
}
