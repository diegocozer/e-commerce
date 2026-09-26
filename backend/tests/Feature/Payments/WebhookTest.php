<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\WebhookEventStatus;
use App\Modules\Payments\Jobs\ProcessWebhookEvent;
use App\Modules\Payments\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Orders\Support\OrdersTestCase;

final class WebhookTest extends OrdersTestCase
{
    public function test_invalid_or_missing_signature_returns_401_empty_and_stores_nothing(): void
    {
        $order = $this->placeOrder();
        $payment = $this->payment($order);
        $this->gateway->set($payment->external_id, PaymentStatus::Approved);

        $this->sendWebhook($payment->external_id, 'evt_x', $this->signedHeaders($payment->external_id, secret: 'wrong'))
            ->assertStatus(401)->assertContent('');
        $this->postJson('/api/v1/webhooks/sandbox', ['id' => 'evt_y', 'data' => ['id' => $payment->external_id]])
            ->assertStatus(401)->assertContent('');
        // Signature for another data.id (payload tampering).
        $this->sendWebhook($payment->external_id, 'evt_z', $this->signedHeaders('other'))->assertStatus(401);

        self::assertSame(0, WebhookEvent::query()->count());
        self::assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
    }

    public function test_signature_outside_tolerance_is_rejected(): void
    {
        $order = $this->placeOrder();
        $payment = $this->payment($order);
        $old = CarbonImmutable::now()->subMinutes(6)->getTimestamp();

        $this->sendWebhook($payment->external_id, 'evt_old', $this->signedHeaders($payment->external_id, $old))->assertStatus(401);
        self::assertSame(0, WebhookEvent::query()->count());
    }

    public function test_previous_secret_is_accepted_during_rotation(): void
    {
        config(['payments.drivers.sandbox.webhook_secret' => 'new-secret', 'payments.drivers.sandbox.webhook_secret_previous' => 'test-secret']);
        $order = $this->placeOrder();
        $payment = $this->payment($order);

        $this->sendWebhook($payment->external_id, 'evt_rot')->assertOk();
    }

    public function test_unknown_provider_is_404(): void
    {
        $this->postJson('/api/v1/webhooks/foo', [])->assertNotFound();
    }

    public function test_valid_webhook_is_stored_once_and_queued_on_webhooks_queue(): void
    {
        Queue::fake();
        $order = $this->placeOrder();
        $payment = $this->payment($order);

        $this->sendWebhook($payment->external_id, 'evt_q')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->sendWebhook($payment->external_id, 'evt_q')->assertOk()->assertExactJson(['status' => 'ok']);

        self::assertSame(1, WebhookEvent::query()->count());
        Queue::assertPushedOn('webhooks', ProcessWebhookEvent::class);
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
        $event = WebhookEvent::query()->firstOrFail();
        self::assertTrue($event->signature_valid);
        self::assertSame(['id' => $payment->external_id], $event->payload['data']);
    }

    public function test_pending_at_gateway_keeps_order_pending_and_marks_event_processed(): void
    {
        $order = $this->placeOrder();
        $payment = $this->payment($order);

        $this->sendWebhook($payment->external_id, 'evt_p')->assertOk();

        self::assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
        self::assertSame(WebhookEventStatus::Processed, WebhookEvent::query()->firstOrFail()->status);
    }

    public function test_unknown_payment_fails_the_job_without_marking_processed(): void
    {
        Queue::fake();
        $this->sendWebhook('fake_unknown', 'evt_u')->assertOk();
        $event = WebhookEvent::query()->firstOrFail();

        try {
            (new ProcessWebhookEvent($event->id))->handle(app(\App\Modules\Payments\Services\DefaultPaymentService::class));
            self::fail('expected PaymentNotFound');
        } catch (\App\Modules\Payments\Exceptions\PaymentNotFound) {
        }
        $event->refresh();
        self::assertNull($event->processed_at);
        self::assertSame(1, $event->attempts);
    }

    public function test_reference_mismatch_is_ignored(): void
    {
        $order = $this->placeOrder();
        $payment = $this->payment($order);
        $this->gateway->payments[$payment->external_id]['reference'] = 'someone-else';
        $this->gateway->set($payment->external_id, PaymentStatus::Approved);

        $this->sendWebhook($payment->external_id, 'evt_ref')->assertOk();
        self::assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
    }

    public function test_reconciliation_command_approves_lost_webhooks(): void
    {
        $order = $this->placeOrder();
        $payment = $this->payment($order);
        $this->gateway->set($payment->external_id, PaymentStatus::Approved);
        $this->travelTo(CarbonImmutable::now()->addMinutes(6));

        Artisan::call('payments:reconcile');

        self::assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    public function test_dev_approve_endpoint_sends_signed_webhook_for_owner_only(): void
    {
        $order = $this->placeOrder();
        $payment = $this->payment($order);
        $this->gateway->set($payment->external_id, PaymentStatus::Approved);

        $this->actingAs(Customer::factory()->create(), 'customer');
        $this->postJson("/api/v1/dev/payments/{$order->uuid}/approve")->assertNotFound();

        $this->actingAs(Customer::query()->findOrFail($order->customer_id), 'customer');
        // The fake gateway is not the simulated sandbox gateway → covered by SandboxGatewayTest; here only IDOR.
        self::assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
    }
}
