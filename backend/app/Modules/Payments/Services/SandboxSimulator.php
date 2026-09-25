<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Gateways\SandboxGateway;
use App\Modules\Payments\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

/**
 * Dev/staging helper (API.md §3.F): changes the sandbox gateway status of the
 * pending payment of an order and sends a SIGNED sandbox webhook through the
 * production pipeline (WebhookIngestor → ProcessWebhookEvent).
 */
final class SandboxSimulator
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly WebhookIngestor $ingestor,
    ) {}

    /** Pending sandbox payment of the order that already reached the gateway. */
    public function pendingPayment(int $orderId): ?Payment
    {
        return Payment::query()->where('order_id', $orderId)
            ->where('provider', PaymentProvider::Sandbox->value)
            ->where('status', PaymentStatus::Pending->value)
            ->whereNotNull('external_id')
            ->orderByDesc('id')->first();
    }

    /** @return string the webhook event external id */
    public function simulate(Payment $payment, PaymentStatus $status, ?int $amountCents = null): string
    {
        $gateway = $this->gateways->gateway(PaymentProvider::Sandbox->value);
        if (! $gateway instanceof SandboxGateway) {
            throw new LogicException('The sandbox driver is not the simulated gateway.');
        }
        $gateway->simulate((string) $payment->external_id, $status, $amountCents);

        $eventId = 'evt_'.Str::upper((string) Str::ulid());
        $body = [
            'id' => $eventId, 'type' => 'payment', 'action' => 'payment.updated',
            'data' => ['id' => $payment->external_id], 'date_created' => CarbonImmutable::now()->toIso8601ZuluString(),
        ];
        $headers = WebhookVerifierFactory::sign(
            (string) config('payments.drivers.sandbox.webhook_secret'),
            strtolower((string) $payment->external_id),
            (string) Str::uuid(),
            CarbonImmutable::now()->getTimestamp(),
        );

        $request = Request::create('/api/v1/webhooks/sandbox', 'POST', server: [
            'HTTP_X_SIGNATURE' => $headers['x-signature'],
            'HTTP_X_REQUEST_ID' => $headers['x-request-id'],
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));

        $this->ingestor->ingest(PaymentProvider::Sandbox, $request);

        return $eventId;
    }
}
