<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Console;

use App\Modules\Shipping\Models\ShippingQuote;
use Illuminate\Console\Command;

/** Deletes quotes expired more than 7 days ago (SHIPPING.md §5.1). Orders keep their own snapshot. */
final class PruneShippingQuotesCommand extends Command
{
    protected $signature = 'shipping:prune-quotes {--days=7 : Keep quotes expired less than N days ago}';

    protected $description = 'Remove shipping quotes expired more than N days ago';

    public function handle(): int
    {
        $threshold = now()->subDays(max(0, (int) $this->option('days')));
        $deleted = ShippingQuote::query()->where('expires_at', '<', $threshold)->delete();
        $this->info("Removed {$deleted} shipping quote(s).");

        return self::SUCCESS;
    }
}
