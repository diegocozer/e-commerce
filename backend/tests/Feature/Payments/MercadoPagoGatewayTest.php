<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Payments\DTOs\PayerData;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use App\Modules\Payments\Exceptions\PaymentGatewayUnavailable;
use App\Modules\Payments\Gateways\MercadoPagoGateway;
use App\Modules\Payments\Services\HmacWebhookVerifier;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MercadoPagoGatewayTest extends TestCase
{
    private MercadoPagoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->gateway = new MercadoPagoGateway(['base_url' => 'https://api.mercadopago.test', 'access_token' => 'TEST-TOKEN']);
    }

    public function test_create_payment_sends_pix_request_with_idempotency_key(): void
    {
        Http::fake(['api.mercadopago.test/v1/payments' => Http::response([
            'id' => 123456, 'status' => 'pending', 'transaction_amount' => 99.5, 'currency_id' => 'BRL',
            'external_reference' => 'ref-uuid', 'date_of_expiration' => '2026-09-24T10:30:00.000-03:00',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020126PIX', 'qr_code_base64' => 'iVBOR']],
            'payer' => ['identification' => ['number' => '52998224725']],
        ], 201)]);

        $result = $this->gateway->createPayment(new PaymentRequest(1, 'CV-000001', PaymentMethod::Pix, Money::ofCents(9950),
            new PayerData('Maria', 'maria@example.com', '52998224725'), CarbonImmutable::parse('2026-09-24T13:30:00Z'), 'ref-uuid'), 'idem-1');

        self::assertSame('123456', $result->externalId);
        self::assertSame(PaymentStatus::Pending, $result->status);
        self::assertSame(9950, $result->amount->cents());
        self::assertSame('00020126PIX', $result->pixCopyPaste);
        self::assertArrayNotHasKey('payer', $result->sanitized);
        Http::assertSent(static fn (ClientRequest $r): bool => $r->hasHeader('X-Idempotency-Key', 'idem-1')
            && $r->hasHeader('Authorization', 'Bearer TEST-TOKEN')
            && $r['payment_method_id'] === 'pix' && $r['transaction_amount'] === 99.5
            && $r['external_reference'] === 'ref-uuid' && $r['payer']['identification']['type'] === 'CPF');
    }

    public function test_get_payment_maps_status_and_errors_become_503(): void
    {
        Http::fake([
            'api.mercadopago.test/v1/payments/1' => Http::response(['id' => 1, 'status' => 'approved', 'transaction_amount' => 10, 'currency_id' => 'BRL', 'date_approved' => '2026-09-24T10:31:00.000-03:00']),
            'api.mercadopago.test/v1/payments/2' => Http::response(['id' => 2, 'status' => 'rejected', 'transaction_amount' => 10, 'status_detail' => 'cc_rejected']),
            'api.mercadopago.test/v1/payments/3' => Http::response([], 500),
        ]);

        $approved = $this->gateway->getPayment('1');
        self::assertSame(PaymentStatus::Approved, $approved->status);
        self::assertSame(1000, $approved->amount->cents());
        self::assertSame('2026-09-24T13:31:00+00:00', $approved->approvedAt?->toIso8601String());
        self::assertSame(PaymentStatus::Failed, $this->gateway->getPayment('2')->status);

        $this->expectException(PaymentGatewayUnavailable::class);
        $this->gateway->getPayment('3');
    }

    public function test_refund(): void
    {
        Http::fake(['api.mercadopago.test/v1/payments/9/refunds' => Http::response(['id' => 77, 'status' => 'approved', 'amount' => 25.9], 201)]);

        $refund = $this->gateway->refund('9', Money::ofCents(2590), 'refund-key');

        self::assertSame(PaymentRefundStatus::Succeeded, $refund->status);
        self::assertSame('77', $refund->externalId);
        Http::assertSent(static fn (ClientRequest $r): bool => $r->hasHeader('X-Idempotency-Key', 'refund-key') && $r['amount'] === 25.9);
    }

    public function test_webhook_signature_scheme(): void
    {
        $verifier = new HmacWebhookVerifier(PaymentProvider::MercadoPago, ['mp-secret']);
        $ts = (string) CarbonImmutable::now()->getTimestamp();
        $manifest = "id:123456;request-id:req-1;ts:{$ts};";
        $request = Request::create('/api/v1/webhooks/mercadopago?data.id=123456&type=payment', 'POST', server: [
            'HTTP_X_SIGNATURE' => "ts={$ts},v1=".hash_hmac('sha256', $manifest, 'mp-secret'),
            'HTTP_X_REQUEST_ID' => 'req-1', 'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['id' => 999, 'type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => '123456']]));

        $n = $verifier->verify($request);
        self::assertSame('999', $n->externalEventId);
        self::assertSame('123456', $n->resourceId);
        self::assertSame('payment.updated', $n->type);

        $request->headers->set('x-signature', "ts={$ts},v1=".hash_hmac('sha256', $manifest, 'other'));
        $this->expectException(InvalidWebhookSignature::class);
        $verifier->verify($request);
    }
}
