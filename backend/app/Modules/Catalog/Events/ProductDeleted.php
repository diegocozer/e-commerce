<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ProductDeleted
{
    use Dispatchable;

    public function __construct(public readonly int $productId) {}
}
