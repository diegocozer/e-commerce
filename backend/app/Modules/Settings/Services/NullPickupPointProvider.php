<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Contracts\PickupPointProvider;

final class NullPickupPointProvider implements PickupPointProvider
{
    public function activePickupPoints(): array
    {
        return [];
    }
}
