<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Customers\Models\Customer;
use App\Modules\Payments\Contracts\PaymentService;
use App\Modules\Payments\Enums\PaymentStatus;
use Tests\Feature\Orders\Support\OrdersTestCase;

final class CustomerOrdersApiTest extends OrdersTestCase
{
    public function test_list_and_detail_of_own_orders(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->placeOrder($customer);
        $this->placeOrder(); // another customer's
        $this->actingAs($customer, 'customer');

        $this->getJson('/api/v1/me/orders')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $order->uuid)
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonPath('data.0.allowed_actions.can_pay', true)
            ->assertJsonPath('data.0.allowed_actions.can_cancel', true)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonMissingPath('data.0.id');

        $this->getJson("/api/v1/me/orders/{$order->uuid}")->assertOk()
            ->assertJsonPath('data.number', $order->number)
            ->assertJsonPath('data.items.0.configuration_label', '2 unidades')
            ->assertJsonPath('data.items.0.billable_quantity', 2)
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.timeline.0.status_label', 'Pedido realizado')
            ->assertJsonPath('data.totals.total_cents', $order->total_cents)
            ->assertJsonStructure(['data' => ['payment' => ['pix' => ['qr_code_base64', 'copy_paste', 'expires_at']], 'shipping' => ['address' => ['formatted']], 'billing']]);

        $this->getJson("/api/v1/me/orders/{$order->uuid}/status")->assertOk()
            ->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.payment.has_pix', true);

        $this->getJson('/api/v1/me/orders?status=paid')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/me/orders?status=bogus')->assertUnprocessable();
    }

    public function test_idor_other_customer_gets_404_everywhere(): void
    {
        $order = $this->placeOrder();
        $this->actingAs(Customer::factory()->create(), 'customer');

        $this->getJson("/api/v1/me/orders/{$order->uuid}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson("/api/v1/me/orders/{$order->uuid}/status")->assertNotFound();
        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancel")->assertNotFound();
        $this->postJson("/api/v1/me/orders/{$order->uuid}/payment", ['payment_method' => 'pix'])->assertNotFound();
        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancellation-request", ['reason' => 'quero cancelar'])->assertNotFound();
        $this->getJson('/api/v1/me/orders/123')->assertNotFound();
        self::assertSame('pending_payment', $order->refresh()->status->value);
    }

    public function test_guest_and_admin_sessions_are_401(): void
    {
        $order = $this->placeOrder();
        $this->getJson("/api/v1/me/orders/{$order->uuid}")->assertUnauthorized();
    }

    public function test_cancellation_request_for_paid_order_only(): void
    {
        $order = $this->placeOrder();
        $customer = Customer::query()->findOrFail($order->customer_id);
        $this->actingAs($customer, 'customer');
        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancellation-request", ['reason' => 'Comprei errado'])->assertStatus(409);

        $this->gateway->set($this->payment($order)->external_id, PaymentStatus::Approved);
        app(PaymentService::class)->syncFromGateway('sandbox', (string) $this->payment($order)->external_id);

        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancel")->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition');
        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancellation-request", ['reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancellation-request", ['reason' => 'Comprei errado'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.cancellation_request.reason', 'Comprei errado')
            ->assertJsonPath('data.allowed_actions.can_request_cancellation', false);
        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancellation-request", ['reason' => 'Comprei errado'])->assertStatus(409);
    }

    public function test_prohibited_fields_and_expired_order_payment(): void
    {
        $order = $this->placeOrder();
        $this->actingAs(Customer::query()->findOrFail($order->customer_id), 'customer');
        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancel", ['status' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/me/orders/{$order->uuid}/payment", ['payment_method' => 'boleto'])->assertUnprocessable();

        $this->travel(31)->minutes();
        $this->postJson("/api/v1/me/orders/{$order->uuid}/payment", ['payment_method' => 'pix'])->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition');
    }
}
