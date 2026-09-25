<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

use App\Modules\Customers\DTOs\CartMergeReport;

/**
 * Guest cart merge on login/registration (inversion: implemented by Cart, BE-CART-06).
 * Customers registers NullGuestCartMerger (cart_merge = null) until then.
 */
interface GuestCartMerger
{
    /** Synchronous, inside login/registration. Never throws: invalid token → report/null. */
    public function merge(?string $guestCartToken, int $customerId): ?CartMergeReport;
}
