<?php

declare(strict_types=1);

namespace App\Modules\Customers\Events;

final readonly class CustomerPasswordReset
{
    public function __construct(public int $customerId) {}
}
