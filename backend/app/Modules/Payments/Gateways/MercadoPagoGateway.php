<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\DTOs\GatewayPayment;
use App\Modules\Payments\DTOs\GatewayRefund;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentGatewayUnavailable;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mercado Pago PIX driver (ARCHITECTURE.md §7.1): POST /v1/payments,
 * GET /v1/payments/{id}, POST /v1/payments/{id}/refunds. No synchronous
 * retries on create/refund (idempotency via X-Idempotency-Key); getPayment
 * retries 2× (100 ms, 500 ms). Only sanitized subsets leave the adapter.
 */
final class MercadoPagoGateway implements PaymentGatewayInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function createPayment(PaymentRequest $request, string $idempotencyKey): GatewayPayment
    {
        $body = array_filter([
            'transaction_amount' => round($request->amount->cents() / 100, 2),
            'description' => 'Pedido '.$request->orderNumber,
            'payment_method_id' => 'pix',
            'external_reference' => $request->externalReference,
            'date_of_expiration' => $request->expiresAt->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:s.vP'),
            'notification_url' => $this->config['notification_url'] ?? null,
            'payer' => array_filter([
                'email' => $request->payer->email,
                'first_name' => $request->payer->name,
                'identification' => $request->payer->document === null ? null : [
                    'type' => $request->payer->documentType(),
                    'number' => $request->payer->document,
                ],
            ]),
        ], static fn (mixed $v): bool => $v !== null);

        $response = $this->send('create', fn (): Response => $this->client($this->timeout('create'))
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->post('/v1/payments', $body));

        return $this->mapPayment($response->json() ?? []);
    }

    public function getPayment(string $externalId): GatewayPayment
    {
        $response = $this->send('get', fn (): Response => $this->client($this->timeout('get'))
            ->retry([100, 500], throw: false)
            ->get('/v1/payments/'.rawurlencode($externalId)));

        return $this->mapPayment($response->json() ?? []);
    }

    public function refund(string $externalId, ?Money $amount, string $idempotencyKey): GatewayRefund
    {
        $body = $amount === null ? [] : ['amount' => round($amount->cents() / 100, 2)];
        $response = $this->send('refund', fn (): Response => $this->client($this->timeout('refund'))
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->post('/v1/payments/'.rawurlencode($externalId).'/refunds', (object) $body));

        $json = $response->json() ?? [];
        $status = match ((string) ($json['status'] ?? '')) {
            'approved' => PaymentRefundStatus::Succeeded,
            'in_process', 'pending', 'authorized' => PaymentRefundStatus::Processing,
            default => PaymentRefundStatus::Failed,
        };

        return new GatewayRefund(
            externalId: (string) ($json['id'] ?? ''),
            status: $status,
            amount: self::toMoney($json['amount'] ?? 0),
            failureReason: $status === PaymentRefundStatus::Failed ? mb_substr((string) ($json['status'] ?? 'rejected'), 0, 255) : null,
            sanitized: ['id' => $json['id'] ?? null, 'status' => $json['status'] ?? null, 'amount' => $json['amount'] ?? null],
        );
    }

    public function supports(PaymentMethod $method): bool
    {
        return $method === PaymentMethod::Pix;
    }

    /** @param array<string, mixed> $json */
    private function mapPayment(array $json): GatewayPayment
    {
        $status = match ((string) ($json['status'] ?? '')) {
            'approved' => PaymentStatus::Approved,
            'rejected' => PaymentStatus::Failed,
            'cancelled' => PaymentStatus::Cancelled,
            'refunded', 'charged_back' => PaymentStatus::Refunded,
            default => PaymentStatus::Pending, // pending, in_process, authorized, in_mediation
        };
        $data = $json['point_of_interaction']['transaction_data'] ?? [];

        return new GatewayPayment(
            externalId: (string) ($json['id'] ?? ''),
            status: $status,
            amount: self::toMoney($json['transaction_amount'] ?? 0),
            currency: (string) ($json['currency_id'] ?? 'BRL'),
            externalReference: isset($json['external_reference']) ? (string) $json['external_reference'] : null,
            pixCopyPaste: isset($data['qr_code']) ? (string) $data['qr_code'] : null,
            pixQrCodeBase64: isset($data['qr_code_base64']) ? (string) $data['qr_code_base64'] : null,
            expiresAt: isset($json['date_of_expiration']) ? CarbonImmutable::parse((string) $json['date_of_expiration'])->utc() : null,
            approvedAt: isset($json['date_approved']) ? CarbonImmutable::parse((string) $json['date_approved'])->utc() : null,
            statusDetail: isset($json['status_detail']) ? mb_substr((string) $json['status_detail'], 0, 255) : null,
            sanitized: [
                'id' => $json['id'] ?? null,
                'status' => $json['status'] ?? null,
                'status_detail' => $json['status_detail'] ?? null,
                'transaction_amount' => $json['transaction_amount'] ?? null,
                'currency_id' => $json['currency_id'] ?? null,
                'external_reference' => $json['external_reference'] ?? null,
                'date_approved' => $json['date_approved'] ?? null,
            ],
        );
    }

    /** @param callable(): Response $call */
    private function send(string $operation, callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            Log::channel('payments')->warning('mercadopago.unavailable', ['operation' => $operation, 'error' => 'connection']);
            throw new PaymentGatewayUnavailable(previous: $e);
        } catch (Throwable $e) {
            Log::channel('payments')->error('mercadopago.error', ['operation' => $operation, 'exception' => $e::class]);
            throw new PaymentGatewayUnavailable(previous: $e);
        }

        if (! $response->successful()) {
            Log::channel('payments')->warning('mercadopago.http_error', ['operation' => $operation, 'status' => $response->status()]);
            throw new PaymentGatewayUnavailable;
        }

        return $response;
    }

    private function client(int $timeout): PendingRequest
    {
        return Http::baseUrl((string) ($this->config['base_url'] ?? 'https://api.mercadopago.com'))
            ->withToken((string) ($this->config['access_token'] ?? ''))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('payments.timeouts.connect', 3))
            ->timeout($timeout);
    }

    private function timeout(string $operation): int
    {
        return (int) config('payments.timeouts.'.$operation, 10);
    }

    private static function toMoney(mixed $amount): Money
    {
        return Money::ofCents((int) round(((float) $amount) * 100));
    }
}
