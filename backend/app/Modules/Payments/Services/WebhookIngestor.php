<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\DTOs\WebhookNotification;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\WebhookEventStatus;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use App\Modules\Payments\Jobs\ProcessWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook pipeline (ARCHITECTURE.md §4.4, SECURITY.md §12): verify the
 * signature BEFORE anything → INSERT … ON CONFLICT DO NOTHING (dedupe of
 * valid events, DB-12) → dispatch ProcessWebhookEvent after commit. Invalid
 * signatures store nothing (SEC-WH-01).
 */
final class WebhookIngestor
{
    public function __construct(private readonly WebhookVerifierFactory $verifiers) {}

    /**
     * @return array{notification: WebhookNotification, event_id: ?int, duplicate: bool}
     *
     * @throws InvalidWebhookSignature
     */
    public function ingest(PaymentProvider $provider, Request $request): array
    {
        try {
            $notification = $this->verifiers->for($provider)->verify($request);
        } catch (InvalidWebhookSignature $e) {
            Log::channel('security')->warning('webhook.invalid_signature', ['provider' => $provider->value, 'reason' => $e->getMessage(), 'ip' => $request->ip()]);
            throw $e;
        }

        $isPayment = str_starts_with($notification->type, 'payment') || ($notification->payload['type'] ?? null) === 'payment';

        $row = DB::selectOne(
            'INSERT INTO webhook_events (provider, external_id, event_type, payload, signature_valid, status, attempts, received_ip, created_at, updated_at)
             VALUES (?, ?, ?, ?::jsonb, true, ?, 0, ?, now(), now())
             ON CONFLICT (provider, external_id) WHERE signature_valid DO NOTHING
             RETURNING id',
            [
                $provider->value,
                $notification->externalEventId,
                $notification->type,
                json_encode($notification->payload, JSON_THROW_ON_ERROR),
                $isPayment ? WebhookEventStatus::Received->value : WebhookEventStatus::Ignored->value,
                $request->ip(),
            ],
        );

        $eventId = $row !== null ? (int) $row->id : null;
        Log::channel('payments')->info('webhook.received', ['provider' => $provider->value, 'webhook_event_id' => $eventId, 'duplicate' => $eventId === null, 'type' => $notification->type]);

        if ($eventId !== null && $isPayment) {
            ProcessWebhookEvent::dispatch($eventId)->afterCommit();
        }

        return ['notification' => $notification, 'event_id' => $eventId, 'duplicate' => $eventId === null];
    }
}
