<?php

declare(strict_types=1);

/*
 * Separate PHP process used by InventoryConcurrencyTest: waits for a barrier
 * file, then tries to reserve stock through the real InventoryService on its
 * own database connection. Prints OK or INSUFFICIENT.
 */

use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockReservation;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Shared\Domain\Quantity;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $variantId, $orderId, $quantity, $barrier] = $argv;

$deadline = microtime(true) + 20;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(500);
}

try {
    app(InventoryService::class)->reserve(new StockReservation('order', (int) $orderId, [
        new StockLine((int) $variantId, Quantity::fromString($quantity)),
    ]));
    echo 'OK';
} catch (InsufficientStock) {
    echo 'INSUFFICIENT';
}
