<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Services\PaymentGatewayManager;
use Tests\Feature\Orders\Support\OrdersTestCase;

/** Real sandbox driver: fake PIX + dev approve/fail through the signed webhook pipeline. */
final class SandboxGatewayTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(PaymentGatewayManager::class)->forgetDrivers();
        $manager = app(PaymentGatewayManager::class);
        (fn () => $this->customCreators = [])->call($manager);
    }

    public function test_sandbox_generates_pix_and_dev_approve_pays_the_order(): void
    {
        $variant = $this->variant('10');
        $order = $this->placeOrder(lines: [[$variant, '2']]);
        $payment = $this->payment($order);
        self::assertStringStartsWith('sbx_pay_', (string) $payment->external_id);
        self::assertStringContainsString('SANDBOX', (string) $payment->pix_copy_paste);
        self::assertNotEmpty($payment->pix_qr_code_base64);

        $this->actingAs(Customer::query()->findOrFail($order->customer_id), 'customer');
        $this->postJson("/api/v1/dev/payments/{$order->uuid}/approve")
            ->assertStatus(202)->assertJsonPath('data.status', 'dispatched');

        self::assertSame(OrderStatus::Paid, $order->refresh()->status);
        self::assertSame(['on_hand' => '8.000', 'reserved' => '0.000'], $this->stock($variant));
        // Nothing pending anymore → 409.
        $this->postJson("/api/v1/dev/payments/{$order->uuid}/approve")->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition');
    }

    public function test_dev_approve_with_other_amount_flags_mismatch_and_fail_marks_failed(): void
    {
        $order = $this->placeOrder();
        $this->actingAs(Customer::query()->findOrFail($order->customer_id), 'customer');

        $this->postJson("/api/v1/dev/payments/{$order->uuid}/approve", ['amount_cents' => 1])->assertStatus(202);
        self::assertSame(OrderStatus::PendingPayment, $order->refresh()->status);

        $this->postJson("/api/v1/dev/payments/{$order->uuid}/fail")->assertStatus(202);
        self::assertSame('failed', $order->refresh()->payment_status->value);
    }
}
