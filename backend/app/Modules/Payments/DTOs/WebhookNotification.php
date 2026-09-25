<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

use App\Modules\Payments\Enums\PaymentProvider;
use Carbon\CarbonImmutable;

/**
 * Verified webhook essentials: `$externalEventId` is the dedupe key
 * (webhook_events.external_id), `$resourceId` the gateway payment id
 * (data.id). `$payload` is already reduced to the non-sensitive notification.
 */
final readonly class WebhookNotification
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public PaymentProvider $provider,
        public string $externalEventId,
        public string $resourceId,
        public string $type,
        public ?CarbonImmutable $occurredAt,
        public array $payload,
    ) {}
}
