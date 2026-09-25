<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Enums\WebhookEventStatus;
use App\Modules\Payments\Jobs\ProcessWebhookEvent;
use App\Modules\Payments\Models\WebhookEvent;
use Illuminate\Console\Command;

/** webhooks:retry-unprocessed — every 10 min: re-queues valid events without processed_at older than 10 min. */
final class RetryUnprocessedWebhooksCommand extends Command
{
    protected $signature = 'webhooks:retry-unprocessed {--limit=200}';

    protected $description = 'Reenfileira webhooks válidos não processados.';

    public function handle(): int
    {
        $ids = WebhookEvent::query()
            ->where('signature_valid', true)
            ->whereIn('status', [WebhookEventStatus::Received->value, WebhookEventStatus::Failed->value])
            ->whereNull('processed_at')
            ->where('created_at', '<', now()->subMinutes(10))
            ->where('created_at', '>', now()->subDays(3))
            ->orderBy('id')->limit((int) $this->option('limit'))->pluck('id');

        foreach ($ids as $id) {
            ProcessWebhookEvent::dispatch((int) $id);
        }
        $this->info(count($ids).' webhook(s) reenfileirado(s).');

        return self::SUCCESS;
    }
}
