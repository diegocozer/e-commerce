<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

/**
 * Active pickup points for GET /settings/public (API.md §2.11). Inversion:
 * implemented by Shipping (which depends on Settings); NullPickupPointProvider
 * returns [] until then.
 */
interface PickupPointProvider
{
    /**
     * @return list<array{method_code:string, name:string, street:string, number:string, complement:?string,
     *     district:string, city:string, state:string, postal_code:string, opening_hours:?string, instructions:?string}>
     */
    public function activePickupPoints(): array;
}
