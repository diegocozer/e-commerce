<?php

declare(strict_types=1);

namespace App\Modules\Cart\Console;

use App\Modules\Cart\Models\Cart;
use Illuminate\Console\Command;

/** DATABASE.md §6 `PurgeExpiredCarts` (daily): expired guest carts and carts converted > 30 days ago. */
final class PurgeExpiredCarts extends Command
{
    protected $signature = 'carts:prune';

    protected $description = 'Delete expired guest carts and carts converted more than 30 days ago';

    public function handle(): int
    {
        $deleted = Cart::query()
            ->where(fn ($q) => $q->whereNull('converted_at')->whereNull('customer_id')->where('expires_at', '<', now()))
            ->orWhere('converted_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Carrinhos removidos: {$deleted}");

        return self::SUCCESS;
    }
}
