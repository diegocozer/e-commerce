<?php

declare(strict_types=1);

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Actions\ExpirePendingOrders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** orders:expire-pending — every minute (ARCHITECTURE.md §4.5). */
final class ExpirePendingOrdersCommand extends Command
{
    protected $signature = 'orders:expire-pending';

    protected $description = 'Cancela pedidos aguardando pagamento com prazo vencido.';

    public function handle(ExpirePendingOrders $action): int
    {
        $count = $action->execute(CarbonImmutable::now());
        $this->info("{$count} pedido(s) expirado(s).");

        return self::SUCCESS;
    }
}
