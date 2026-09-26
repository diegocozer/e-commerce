<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Notifications\Channels\WhatsAppChannel;
use App\Modules\Notifications\Contracts\WhatsAppClient;
use App\Modules\Notifications\Notifications\AdminAlertNotification;
use App\Modules\Notifications\Notifications\OrderCancelledNotification;
use App\Modules\Notifications\Notifications\OrderDeliveredNotification;
use App\Modules\Notifications\Notifications\OrderPaidNotification;
use App\Modules\Notifications\Notifications\OrderReadyForPickupNotification;
use App\Modules\Notifications\Notifications\OrderReceivedNotification;
use App\Modules\Notifications\Notifications\OrderShippedNotification;
use App\Modules\Notifications\Notifications\PaymentFailedNotification;
use App\Modules\Notifications\Notifications\RefundProcessedNotification;
use App\Modules\Notifications\Services\NullWhatsAppClient;
use App\Modules\Orders\Actions\ChangeOrderStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Shared\Domain\ActorRef;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Orders\Support\OrdersTestCase;

final class OrderNotificationsTest extends OrdersTestCase
{
    public function test_order_created_and_paid_notifications_are_sent_once(): void
    {
        Notification::fake();
        $order = $this->placeOrder();
        $customer = Customer::query()->findOrFail($order->customer_id);
        Notification::assertSentTo($customer, OrderReceivedNotification::class, static function (OrderReceivedNotification $n, array $channels): bool {
            return $n->queue === 'notifications' && $n->afterCommit === true && $channels === ['mail', 'database'];
        });

        $payment = $this->payment($order);
        $this->gateway->set((string) $payment->external_id, PaymentStatus::Approved);
        $this->sendWebhook((string) $payment->external_id, 'evt_1')->assertOk();
        $this->sendWebhook((string) $payment->external_id, 'evt_1')->assertOk();
        $this->sendWebhook((string) $payment->external_id, 'evt_2')->assertOk();

        Notification::assertSentToTimes($customer, OrderPaidNotification::class, 1);
    }

    public function test_status_change_cancel_refund_and_failure_notifications(): void
    {
        $admin = $this->actingAsAdmin([], AdminRole::SuperAdmin);
        Notification::fake();
        $order = $this->placeOrder();
        $customer = Customer::query()->findOrFail($order->customer_id);
        $payment = $this->payment($order);

        $this->gateway->set((string) $payment->external_id, PaymentStatus::Failed);
        $this->sendWebhook((string) $payment->external_id, 'evt_f')->assertOk();
        Notification::assertSentTo($customer, PaymentFailedNotification::class);

        $retry = $this->placeOrder($customer);
        $p2 = $this->payment($retry);
        $this->gateway->set((string) $p2->external_id, PaymentStatus::Approved);
        $this->sendWebhook((string) $p2->external_id, 'evt_ok')->assertOk();
        Notification::assertSentTo($admin, AdminAlertNotification::class, static fn (AdminAlertNotification $n): bool => $n->type === 'order_paid');

        $change = app(ChangeOrderStatus::class);
        $change->execute($retry->refresh(), OrderStatus::Processing, ActorRef::admin($admin->id));
        $change->execute($retry->refresh(), OrderStatus::Shipped, ActorRef::admin($admin->id));
        Notification::assertSentTo($customer, OrderShippedNotification::class);
        $change->execute($retry->refresh(), OrderStatus::Delivered, ActorRef::admin($admin->id));
        Notification::assertSentTo($customer, OrderDeliveredNotification::class);

        $pickup = $this->placeOrder($customer, methodType: 'pickup');
        $p3 = $this->payment($pickup);
        $this->gateway->set((string) $p3->external_id, PaymentStatus::Approved);
        $this->sendWebhook((string) $p3->external_id, 'evt_p3')->assertOk();
        $change->execute($pickup->refresh(), OrderStatus::Processing, ActorRef::admin($admin->id));
        $change->execute($pickup->refresh(), OrderStatus::ReadyForPickup, ActorRef::admin($admin->id));
        Notification::assertSentTo($customer, OrderReadyForPickupNotification::class);

        $paid = $this->placeOrder($customer);
        $p4 = $this->payment($paid);
        $this->gateway->set((string) $p4->external_id, PaymentStatus::Approved);
        $this->sendWebhook((string) $p4->external_id, 'evt_p4')->assertOk();
        $this->postJson("/api/v1/admin/orders/{$paid->id}/cancel", ['reason' => 'Sem retirada', 'confirm_refund' => true])->assertOk();
        Notification::assertSentTo($customer, OrderCancelledNotification::class, static fn (OrderCancelledNotification $n): bool => $n->wasPaid && $n->reason === 'admin');
        Notification::assertSentTo($customer, RefundProcessedNotification::class);
    }

    public function test_refund_failure_alerts_finance(): void
    {
        $this->actingAsAdmin([], AdminRole::Finance);
        $finance = AdminUser::query()->latest('id')->firstOrFail();
        Notification::fake();
        $order = $this->placeOrder();
        $payment = $this->payment($order);
        $this->gateway->set((string) $payment->external_id, PaymentStatus::Approved);
        $this->sendWebhook((string) $payment->external_id, 'evt_a')->assertOk();
        $this->gateway->refundResult = PaymentRefundStatus::Failed;

        try {
            $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => 'Teste', 'confirm_refund' => true]);
        } catch (\Throwable) {
            // sync queue rethrows the job failure
        }

        self::assertSame('failed', $payment->refunds()->firstOrFail()->status->value);
        Notification::assertSentTo($finance, AdminAlertNotification::class, static fn (AdminAlertNotification $n): bool => $n->type === 'refund_failed');
    }

    public function test_real_delivery_renders_pt_br_mail_and_database_timeline_and_whatsapp_stub(): void
    {
        Mail::fake();
        app(SettingsRepository::class)->set(SettingKey::NotificationsWhatsappEnabled, true);
        $client = new NullWhatsAppClient;
        $this->app->instance(WhatsAppClient::class, $client);

        $order = $this->placeOrder();
        $customer = Customer::query()->findOrFail($order->customer_id);
        $payment = $this->payment($order);
        $this->gateway->set((string) $payment->external_id, PaymentStatus::Approved);
        $this->sendWebhook((string) $payment->external_id, 'evt_real')->assertOk();

        $notification = new OrderPaidNotification($order->id);
        $html = (string) $notification->toMail($customer)->render();
        self::assertStringContainsString('Pagamento aprovado', $html);
        self::assertStringContainsString($order->number, $html);
        self::assertContains(WhatsAppChannel::class, $notification->via($customer));
        self::assertNotEmpty($client->sent);

        $this->actingAs($customer, 'customer');
        $types = $this->getJson('/api/v1/me/notifications')->assertOk()
            ->assertJsonPath('data.0.read_at', null)->json('data.*.type');
        self::assertEqualsCanonicalizing(['order_created', 'order_paid'], $types);
        $this->postJson('/api/v1/me/notifications/read')->assertNoContent();
        $this->getJson('/api/v1/me/notifications?unread=1')->assertOk()->assertJsonCount(0, 'data');
    }
}
