<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\DTOs\GatewayPayment;
use App\Modules\Payments\DTOs\GatewayRefund;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Simulated PIX gateway (ADR-010). Charges live in the cache
 * (`payments:sandbox:{externalId}`); unknown ids fall back to the local
 * `payments` row as pending. Approval/failure is simulated through
 * {@see self::simulate()} (dev endpoints / payments:sandbox-approve), which
 * then sends a signed webhook through the production pipeline.
 */
final class SandboxGateway implements PaymentGatewayInterface
{
    private const string PREFIX = 'payments:sandbox:';

    public function __construct(private readonly Cache $cache) {}

    public function createPayment(PaymentRequest $request, string $idempotencyKey): GatewayPayment
    {
        $existing = $this->cache->get(self::PREFIX.'idem:'.$idempotencyKey);
        if (is_string($existing)) {
            return $this->getPayment($existing);
        }

        $externalId = 'sbx_pay_'.Str::lower((string) Str::ulid());
        $copyPaste = self::copyPaste($request->amount, $externalId);
        $state = [
            'status' => PaymentStatus::Pending->value,
            'amount_cents' => $request->amount->cents(),
            'currency' => 'BRL',
            'reference' => $request->externalReference,
            'copy_paste' => $copyPaste,
            'expires_at' => $request->expiresAt->toIso8601String(),
            'approved_at' => null,
        ];
        $this->cache->forever(self::PREFIX.$externalId, $state);
        $this->cache->forever(self::PREFIX.'idem:'.$idempotencyKey, $externalId);

        return $this->toGatewayPayment($externalId, $state, withQr: true);
    }

    public function getPayment(string $externalId): GatewayPayment
    {
        $state = $this->cache->get(self::PREFIX.$externalId);
        if (! is_array($state)) {
            $payment = Payment::query()->where('provider', PaymentProvider::Sandbox->value)->where('external_id', $externalId)->first();
            $state = [
                'status' => PaymentStatus::Pending->value,
                'amount_cents' => $payment?->amount_cents ?? 0,
                'currency' => 'BRL',
                'reference' => $payment?->uuid,
                'copy_paste' => $payment?->pix_copy_paste,
                'expires_at' => $payment?->expires_at?->toIso8601String(),
                'approved_at' => null,
            ];
        }

        return $this->toGatewayPayment($externalId, $state);
    }

    public function refund(string $externalId, ?Money $amount, string $idempotencyKey): GatewayRefund
    {
        $state = $this->getPayment($externalId);
        $refundAmount = $amount ?? $state->amount;

        return new GatewayRefund(
            externalId: 'sbx_ref_'.substr(hash('sha256', $idempotencyKey), 0, 20),
            status: PaymentRefundStatus::Succeeded,
            amount: $refundAmount,
            sanitized: ['status' => 'approved', 'amount_cents' => $refundAmount->cents()],
        );
    }

    public function supports(PaymentMethod $method): bool
    {
        return $method === PaymentMethod::Pix;
    }

    /** Dev/staging only: sets the simulated gateway status (optionally with another amount). */
    public function simulate(string $externalId, PaymentStatus $status, ?int $amountCents = null): void
    {
        $current = $this->getPayment($externalId);
        $state = $this->cache->get(self::PREFIX.$externalId);
        $state = is_array($state) ? $state : [
            'amount_cents' => $current->amount->cents(), 'currency' => 'BRL', 'reference' => $current->externalReference,
            'copy_paste' => $current->pixCopyPaste, 'expires_at' => $current->expiresAt?->toIso8601String(),
        ];
        $state['status'] = $status->value;
        $state['approved_at'] = $status === PaymentStatus::Approved ? CarbonImmutable::now()->toIso8601String() : null;
        if ($amountCents !== null) {
            $state['amount_cents'] = $amountCents;
        }
        $this->cache->forever(self::PREFIX.$externalId, $state);
    }

    /** @param array<string, mixed> $state */
    private function toGatewayPayment(string $externalId, array $state, bool $withQr = false): GatewayPayment
    {
        $status = PaymentStatus::from((string) $state['status']);

        return new GatewayPayment(
            externalId: $externalId,
            status: $status,
            amount: Money::ofCents((int) $state['amount_cents']),
            currency: (string) $state['currency'],
            externalReference: $state['reference'] ?? null,
            pixCopyPaste: $state['copy_paste'] ?? null,
            pixQrCodeBase64: $withQr && isset($state['copy_paste']) ? FakeQrCode::png((string) $state['copy_paste']) : null,
            expiresAt: isset($state['expires_at']) ? CarbonImmutable::parse((string) $state['expires_at']) : null,
            approvedAt: isset($state['approved_at']) ? CarbonImmutable::parse((string) $state['approved_at']) : null,
            statusDetail: $status === PaymentStatus::Failed ? 'cc_rejected_other_reason' : null,
            sanitized: ['id' => $externalId, 'status' => $status->value, 'amount_cents' => (int) $state['amount_cents'], 'currency' => $state['currency']],
        );
    }

    private static function copyPaste(Money $amount, string $externalId): string
    {
        $value = number_format($amount->cents() / 100, 2, '.', '');

        return '00020126580014br.gov.bcb.pix0136'.substr(hash('sha256', $externalId), 0, 36)
            .'520400005303986540'.strlen($value).$value.'5802BR5915CV SUPRIMENTOS6008BLUMENAU62'
            .sprintf('%02d', strlen($externalId) + 4).'05'.sprintf('%02d', strlen($externalId)).$externalId.'SANDBOX6304'
            .strtoupper(substr(hash('crc32b', $externalId), 0, 4));
    }
}
