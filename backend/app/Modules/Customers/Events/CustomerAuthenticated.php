<?php

declare(strict_types=1);

namespace App\Modules\Customers\Events;

/** Dispatched after login/registration. The guest cart merge itself goes through GuestCartMerger. */
final readonly class CustomerAuthenticated
{
    public function __construct(public int $customerId, public ?string $guestCartToken) {}
}
