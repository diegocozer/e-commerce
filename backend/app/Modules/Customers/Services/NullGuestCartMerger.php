<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\GuestCartMerger;
use App\Modules\Customers\DTOs\CartMergeReport;

/** Default until Cart binds the real merger (BE-CART-06): cart_merge = null. */
final class NullGuestCartMerger implements GuestCartMerger
{
    public function merge(?string $guestCartToken, int $customerId): ?CartMergeReport
    {
        return null;
    }
}
