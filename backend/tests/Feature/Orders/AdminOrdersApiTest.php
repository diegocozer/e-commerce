<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentService;
use App\Modules\Payments\Enums\PaymentStatus;
use Tests\Feature\Orders\Support\OrdersTestCase;

final class AdminOrdersApiTest extends OrdersTestCase
{
    private function paidOrder(string $methodType = 'own_delivery'): Order
    {
        $order = $this->placeOrder(methodType: $methodType);
        $this->gateway->set((string) $this->payment($order)->external_id, PaymentStatus::Approved);
        app(PaymentService::class)->syncFromGateway('sandbox', (string) $this->payment($order)->external_id);

        return $order->refresh();
    }

    public function test_permissions_per_endpoint(): void
    {
        $order = $this->placeOrder();
        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();

        $this->actingAsAdmin(['dashboard.view']);
        $this->getJson('/api/v1/admin/orders')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertForbidden();
        $this->postJson("/api/v1/admin/orders/{$order->id}/transitions", ['to_status' => 'processing'])->assertForbidden();
        $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'teste'])->assertForbidden();
        $this->postJson("/api/v1/admin/orders/{$order->id}/reveal-document")->assertForbidden();
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/reconcile")->assertForbidden();

        $this->actingAsAdmin(['orders.view', 'orders.cancel_paid']);
        // pending order needs orders.cancel_unpaid
        $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'teste'])->assertForbidden();
        $this->patchJson("/api/v1/admin/orders/{$order->id}", ['internal_notes' => 'x'])->assertForbidden();

        $this->actingAsAdmin([], AdminRole::Seller);
        $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'teste'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_list_filters_detail_and_status_counts(): void
    {
        $pending = $this->placeOrder();
        $paid = $this->paidOrder();
        $this->actingAsAdmin(['orders.view']);

        $this->getJson('/api/v1/admin/orders')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 25);
        $this->getJson('/api/v1/admin/orders?status=paid&sort=paid_at')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $paid->id);
        $this->getJson('/api/v1/admin/orders?q='.$pending->number)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $pending->number);
        $this->getJson('/api/v1/admin/orders?sort=bogus')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders/status-counts')->assertOk()
            ->assertJsonPath('data.pending_payment', 1)->assertJsonPath('data.paid', 1)->assertJsonPath('data.all', 2);

        $this->getJson("/api/v1/admin/orders/{$paid->id}")->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.allowed_transitions', [])
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.payments.0.transactions', null)
            ->assertJsonPath('data.status_history.0.actor.type', 'system');
    }

    public function test_transitions_happy_path_invalid_and_pickup(): void
    {
        $order = $this->paidOrder();
        $this->actingAsAdmin(['orders.view', 'orders.fulfill', 'payments.view']);

        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertJsonPath('data.allowed_transitions.0.to_status', 'processing')
            ->assertJsonPath('data.payments.0.transactions.0.type', 'create');
        $this->postJson("/api/v1/admin/orders/{$order->id}/transitions", ['to_status' => 'shipped'])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_status_transition')->assertJsonPath('allowed_transitions', ['processing']);
        $this->postJson("/api/v1/admin/orders/{$order->id}/transitions", ['to_status' => 'paid'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/orders/{$order->id}/transitions", ['to_status' => 'processing'])->assertOk()->assertJsonPath('data.status', 'processing');
        $this->postJson("/api/v1/admin/orders/{$order->id}/transitions", ['to_status' => 'ready_for_pickup'])->assertStatus(409);
        $this->postJson("/api/v1/admin/orders/{$order->id}/transitions", ['to_status' => 'shipped', 'note' => 'Saiu com o motorista João.', 'tracking_code' => 'AB123'])
            ->assertOk()->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.shipping.tracking_code', 'AB123')
            ->assertJsonPath('data.allowed_transitions.0.to_status', 'delivered');
        $this->postJson("/api/v1/admin/orders/{$order->id}/transitions", ['to_status' => 'shipped'])
            ->assertStatus(409)->assertJson(['message' => 'Transição de status inválida: enviado → enviado.', 'allowed_transitions' => ['delivered']]);
        $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'teste'])->assertForbidden();

        $pickup = $this->paidOrder('pickup');
        $this->postJson("/api/v1/admin/orders/{$pickup->id}/transitions", ['to_status' => 'processing'])->assertOk();
        $this->postJson("/api/v1/admin/orders/{$pickup->id}/transitions", ['to_status' => 'ready_for_pickup'])->assertOk();

        $this->actingAsAdmin(['orders.view', 'orders.pickup']);
        $this->postJson("/api/v1/admin/orders/{$pickup->id}/transitions", ['to_status' => 'picked_up'])->assertUnprocessable()
            ->assertJsonValidationErrors(['picked_up_by_name', 'picked_up_by_document']);
        $this->postJson("/api/v1/admin/orders/{$pickup->id}/transitions", ['to_status' => 'picked_up', 'picked_up_by_name' => 'José Souza', 'picked_up_by_document' => '123.456.789-09'])
            ->assertOk()->assertJsonPath('data.status', 'picked_up')->assertJsonPath('data.shipping.picked_up_by_document_masked', '********909');
        $this->postJson("/api/v1/admin/orders/{$order->id}/transitions", ['to_status' => 'delivered'])->assertForbidden();
    }

    public function test_carrier_shipping_requires_tracking_code(): void
    {
        $order = $this->paidOrder();
        $order->forceFill(['shipping_method_type' => 'carrier', 'status' => OrderStatus::Processing, 'processing_at' => now()])->save();
        $this->actingAsAdmin(['orders.view', 'orders.fulfill']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/transitions", ['to_status' => 'shipped'])->assertUnprocessable()->assertJsonValidationErrors('tracking_code');
    }

    public function test_patch_notes_dismiss_reveal_and_reconcile(): void
    {
        $order = $this->paidOrder();
        $order->forceFill(['cancellation_requested_at' => now(), 'cancellation_request_reason' => 'errado'])->save();
        $this->actingAsAdmin(['orders.view', 'orders.notes', 'orders.cancel_paid', 'customers.view_sensitive', 'payments.reconcile']);

        $this->patchJson("/api/v1/admin/orders/{$order->id}", ['internal_notes' => 'Ligar antes', 'status' => 'delivered'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson("/api/v1/admin/orders/{$order->id}", ['internal_notes' => 'Ligar antes'])->assertOk()->assertJsonPath('data.internal_notes', 'Ligar antes');
        $this->patchJson("/api/v1/admin/orders/{$order->id}", ['tracking_code' => 'X'])->assertForbidden();

        $this->postJson("/api/v1/admin/orders/{$order->id}/cancellation-request/dismiss", ['note' => 'Já enviado'])->assertOk()->assertJsonPath('data.cancellation_request', null);
        $this->postJson("/api/v1/admin/orders/{$order->id}/cancellation-request/dismiss", ['note' => 'Já enviado'])->assertStatus(409);

        $this->postJson("/api/v1/admin/orders/{$order->id}/reveal-document")->assertOk()->assertJsonPath('data.customer_document', $order->customer_document);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.sensitive_viewed']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/reconcile")->assertOk()->assertJsonPath('data.status', 'paid');
    }
}
