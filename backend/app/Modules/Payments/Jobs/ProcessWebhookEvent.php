<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Enums\WebhookEventStatus;
use App\Modules\Payments\Models\WebhookEvent;
use App\Modules\Payments\Services\DefaultPaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes a stored, signature-valid webhook (queue `webhooks`): asks the
 * gateway for the payment (source of truth) and applies the transition
 * idempotently. Unknown payment (webhook before the checkout commit, Q-16)
 * fails and is retried; the reconciliation covers what is left.
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly int $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800];
    }

    public function handle(DefaultPaymentService $payments): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if ($event === null || ! $event->signature_valid || $event->status === WebhookEventStatus::Processed) {
            return;
        }

        $event->forceFill(['attempts' => $event->attempts + 1])->save();
        $resourceId = (string) ($event->payload['data']['id'] ?? '');

        try {
            $payment = $payments->syncFromGateway($event->provider->value, $resourceId, $event->id);
        } catch (Throwable $e) {
            $event->forceFill(['error' => mb_substr($e::class.': '.$e->getMessage(), 0, 1000)])->save();
            Log::channel('payments')->warning('webhook.processing_failed', ['webhook_event_id' => $event->id, 'provider' => $event->provider->value, 'exception' => $e::class]);
            throw $e;
        }

        $event->forceFill([
            'status' => WebhookEventStatus::Processed,
            'payment_id' => $payment->id,
            'processed_at' => CarbonImmutable::now(),
            'error' => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)
            ->where('status', '!=', WebhookEventStatus::Processed->value)
            ->update(['status' => WebhookEventStatus::Failed->value, 'updated_at' => now()]);
    }
}
