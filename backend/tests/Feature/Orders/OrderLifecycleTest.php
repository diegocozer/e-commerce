<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Orders\Actions\ExpirePendingOrders;
use App\Modules\Orders\Enums\CancelReasonCode;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\TooManyPendingOrders;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Payments\Contracts\PaymentService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Customers\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Orders\Support\OrdersTestCase;

/** Integration with the real InventoryService/CouponService (B-B). */
final class OrderLifecycleTest extends OrdersTestCase
{
    public function test_place_order_reserves_stock_writes_snapshot_history_and_number(): void
    {
        $variant = $this->variant('10');
        $order = $this->placeOrder(lines: [[$variant, '3']]);

        self::assertMatchesRegularExpression('/^CV-\d{6,}$/', $order->number);
        self::assertSame(OrderStatus::PendingPayment, $order->status);
        self::assertSame(['on_hand' => '10.000', 'reserved' => '3.000'], $this->stock($variant));
        self::assertSame(1, $order->items()->count());
        self::assertSame(1, OrderStatusHistory::query()->where('order_id', $order->id)->count());
        $payment = $this->payment($order);
        self::assertSame(PaymentStatus::Pending, $payment->status);
        self::assertNotNull($payment->external_id);
        self::assertNotNull($payment->pix_copy_paste);
        // Gateway is never called inside a transaction (RefreshDatabase keeps level 1).
        self::assertSame(1, $this->gateway->calls[0]['tx_level']);
    }

    public function test_place_order_fails_without_stock_and_limits_pending_orders(): void
    {
        $customer = Customer::factory()->create();
        $variant = $this->variant('1');
        try {
            $this->placeOrder($customer, [[$variant, '2']]);
            self::fail('expected InsufficientStock');
        } catch (InsufficientStock) {
            self::assertSame(0, \App\Modules\Orders\Models\Order::query()->count());
        }

        for ($i = 0; $i < 3; $i++) {
            $this->placeOrder($customer);
        }
        $this->expectException(TooManyPendingOrders::class);
        $this->placeOrder($customer);
    }

    public function test_approval_via_webhook_commits_stock_once(): void
    {
        $variant = $this->variant('10');
        $order = $this->placeOrder(lines: [[$variant, '2']]);
        $payment = $this->payment($order);
        $this->gateway->set($payment->external_id, PaymentStatus::Approved);

        $this->sendWebhook($payment->external_id, 'evt_a')->assertOk()->assertExactJson(['status' => 'ok']);
        // Replay of the same event and a second distinct approval event: idempotent.
        $this->sendWebhook($payment->external_id, 'evt_a')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->sendWebhook($payment->external_id, 'evt_b')->assertOk();

        $order->refresh();
        self::assertSame(OrderStatus::Paid, $order->status);
        self::assertSame(OrderPaymentStatus::Approved, $order->payment_status);
        self::assertNotNull($order->paid_at);
        self::assertNull($order->expires_at);
        self::assertSame(['on_hand' => '8.000', 'reserved' => '0.000'], $this->stock($variant));
        self::assertSame(1, $payment->transactions()->where('type', 'approve')->count());
        self::assertSame(2, \App\Modules\Payments\Models\WebhookEvent::query()->count());
    }

    public function test_customer_cancel_releases_stock_and_payment(): void
    {
        $variant = $this->variant('10');
        $order = $this->placeOrder(lines: [[$variant, '4']]);
        $this->actingAs(Customer::query()->findOrFail($order->customer_id), 'customer');

        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancel", ['reason' => 'Desisti'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason_code', 'customer');

        self::assertSame(['on_hand' => '10.000', 'reserved' => '0.000'], $this->stock($variant));
        self::assertSame(PaymentStatus::Cancelled, $this->payment($order)->status);
        // Second cancel → 409.
        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancel")->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition');
    }

    public function test_admin_cancel_paid_order_restocks_and_refunds(): void
    {
        $variant = $this->variant('10');
        $order = $this->placeOrder(lines: [[$variant, '2']]);
        $payment = $this->payment($order);
        $this->gateway->set($payment->external_id, PaymentStatus::Approved);
        app(PaymentService::class)->syncFromGateway('sandbox', $payment->external_id);
        self::assertSame(['on_hand' => '8.000', 'reserved' => '0.000'], $this->stock($variant));

        $this->actingAsAdmin(['orders.view', 'orders.cancel_paid']);
        $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'Cliente pediu'])->assertUnprocessable()->assertJsonValidationErrors('confirm_refund');
        $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'Cliente pediu', 'confirm_refund' => true])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_requires_refund', false);

        self::assertSame(['on_hand' => '10.000', 'reserved' => '0.000'], $this->stock($variant));
        $order->refresh();
        // ProcessRefund ran (sync queue) → refunded.
        self::assertSame(OrderPaymentStatus::Refunded, $order->payment_status);
        self::assertNotNull($order->refunded_at);
        self::assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        self::assertSame(1, $this->gateway->count('refund'));
        self::assertSame(1, $this->gateway->calls[array_key_last($this->gateway->calls)]['tx_level']);
    }

    public function test_expiry_job_cancels_expired_orders_and_releases_stock(): void
    {
        $variant = $this->variant('10');
        $expired = $this->placeOrder(lines: [[$variant, '2']]);
        $fresh = $this->placeOrder(lines: [[$variant, '1']]);

        $this->travelTo(CarbonImmutable::now()->addMinutes(32));
        $fresh->forceFill(['expires_at' => CarbonImmutable::now()->addMinutes(5)])->save();
        Artisan::call('orders:expire-pending');

        $expired->refresh();
        self::assertSame(OrderStatus::Cancelled, $expired->status);
        self::assertSame(CancelReasonCode::PaymentExpired, $expired->cancel_reason_code);
        self::assertSame(OrderPaymentStatus::Expired, $expired->payment_status);
        self::assertSame(PaymentStatus::Expired, $this->payment($expired)->status);
        self::assertSame(OrderStatus::PendingPayment, $fresh->refresh()->status);
        self::assertSame(['on_hand' => '10.000', 'reserved' => '1.000'], $this->stock($variant));
        self::assertGreaterThanOrEqual(1, $this->gateway->count('get'), 'final gateway check before expiring');
    }

    public function test_expiry_job_skips_order_approved_at_the_final_check(): void
    {
        $variant = $this->variant('10');
        $order = $this->placeOrder(lines: [[$variant, '2']]);
        $this->gateway->set($this->payment($order)->external_id, PaymentStatus::Approved);

        $this->travelTo(CarbonImmutable::now()->addMinutes(40));
        self::assertSame(0, app(ExpirePendingOrders::class)->execute(CarbonImmutable::now()));
        self::assertSame(OrderStatus::Paid, $order->refresh()->status);
        self::assertSame(['on_hand' => '8.000', 'reserved' => '0.000'], $this->stock($variant));
    }

    public function test_late_payment_reactivates_order_when_stock_is_available(): void
    {
        $variant = $this->variant('10');
        $order = $this->placeOrder(lines: [[$variant, '2']]);
        $payment = $this->payment($order);
        $this->gateway->down = true; // final check fails → expires anyway
        $this->travelTo(CarbonImmutable::now()->addMinutes(40));
        app(ExpirePendingOrders::class)->execute(CarbonImmutable::now());
        self::assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        self::assertSame(['on_hand' => '10.000', 'reserved' => '0.000'], $this->stock($variant));

        $this->gateway->down = false;
        $this->gateway->set($payment->external_id, PaymentStatus::Approved);
        $this->sendWebhook($payment->external_id, 'evt_late')->assertOk();

        $order->refresh();
        self::assertSame(OrderStatus::Paid, $order->status);
        self::assertNull($order->cancel_reason_code);
        self::assertSame(['on_hand' => '8.000', 'reserved' => '0.000'], $this->stock($variant));
        $last = OrderStatusHistory::query()->where('order_id', $order->id)->orderByDesc('id')->firstOrFail();
        self::assertSame(OrderStatus::Cancelled, $last->from_status);
        self::assertSame(OrderStatus::Paid, $last->to_status);
        self::assertSame('system', $last->actor_type->value);
    }

    public function test_late_payment_without_stock_refunds_automatically(): void
    {
        $variant = $this->variant('2');
        $order = $this->placeOrder(lines: [[$variant, '2']]);
        $payment = $this->payment($order);
        $this->gateway->down = true;
        $this->travelTo(CarbonImmutable::now()->addMinutes(40));
        app(ExpirePendingOrders::class)->execute(CarbonImmutable::now());
        $this->gateway->down = false;
        $this->placeOrder(lines: [[$variant, '2']]); // someone else took the stock

        $this->gateway->set($payment->external_id, PaymentStatus::Approved);
        $this->sendWebhook($payment->external_id, 'evt_late2')->assertOk();

        $order->refresh();
        self::assertSame(OrderStatus::Cancelled, $order->status);
        self::assertSame(OrderPaymentStatus::Refunded, $order->payment_status);
        self::assertSame(1, $payment->refunds()->where('status', 'succeeded')->count());
        self::assertSame(['on_hand' => '2.000', 'reserved' => '2.000'], $this->stock($variant));
    }

    public function test_amount_mismatch_is_never_approved(): void
    {
        $order = $this->placeOrder();
        $payment = $this->payment($order);
        $this->gateway->set($payment->external_id, PaymentStatus::Approved, 100);

        $this->sendWebhook($payment->external_id, 'evt_mm')->assertOk();

        self::assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertStringContainsString('Divergência', (string) $order->internal_notes);
        $this->actingAsAdmin(['orders.view']);
        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->assertJsonPath('data.flags.amount_mismatch', true);
    }

    public function test_failed_payment_keeps_order_pending_and_retry_creates_new_pix(): void
    {
        $order = $this->placeOrder();
        $payment = $this->payment($order);
        $this->gateway->set($payment->external_id, PaymentStatus::Failed);
        $this->sendWebhook($payment->external_id, 'evt_fail')->assertOk();

        self::assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
        self::assertSame(OrderPaymentStatus::Failed, $order->payment_status);

        $this->actingAs(Customer::query()->findOrFail($order->customer_id), 'customer');
        $this->postJson("/api/v1/me/orders/{$order->uuid}/payment", ['payment_method' => 'pix'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonStructure(['data' => ['pix' => ['copy_paste', 'qr_code_base64', 'expires_at']]]);
        $this->postJson("/api/v1/me/orders/{$order->uuid}/payment", ['payment_method' => 'pix'])->assertOk();
        self::assertSame(1, \App\Modules\Payments\Models\Payment::query()->where('order_id', $order->id)->where('status', 'pending')->count());
    }

    public function test_retry_payment_after_gateway_failure_and_503(): void
    {
        $this->gateway->down = true;
        try {
            $order = $this->placeOrder();
        } catch (\App\Modules\Payments\Exceptions\PaymentGatewayUnavailable) {
            $order = \App\Modules\Orders\Models\Order::query()->latest('id')->firstOrFail();
        }
        self::assertNull($this->payment($order)->external_id);
        $this->actingAs(Customer::query()->findOrFail($order->customer_id), 'customer');
        $this->postJson("/api/v1/me/orders/{$order->uuid}/payment", ['payment_method' => 'pix'])
            ->assertStatus(503)->assertJsonPath('code', 'payment_gateway_unavailable')->assertJsonPath('order.uuid', $order->uuid);

        $this->gateway->down = false;
        $this->postJson("/api/v1/me/orders/{$order->uuid}/payment", ['payment_method' => 'pix'])->assertOk()->assertJsonPath('data.pix.copy_paste', fn ($v) => is_string($v));
    }
}
