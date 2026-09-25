<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\WebhookEvent;
use Illuminate\Console\Command;

/** webhooks:prune — weekly: deletes processed/ignored events older than 180 days not referenced by transactions. */
final class PruneWebhooksCommand extends Command
{
    protected $signature = 'webhooks:prune {--days=180}';

    protected $description = 'Remove webhook_events antigos já processados.';

    public function handle(): int
    {
        $deleted = WebhookEvent::query()
            ->whereIn('status', ['processed', 'ignored'])
            ->where('created_at', '<', now()->subDays((int) $this->option('days')))
            ->whereNotIn('id', PaymentTransaction::query()->select('webhook_event_id')->whereNotNull('webhook_event_id'))
            ->delete();
        $this->info("{$deleted} webhook(s) removido(s).");

        return self::SUCCESS;
    }
}
