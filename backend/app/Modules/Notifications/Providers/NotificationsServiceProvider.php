<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Contracts\WhatsAppClient;
use App\Modules\Notifications\Listeners\OrderNotificationSubscriber as S;
use App\Modules\Notifications\Services\LogWhatsAppClient;
use App\Modules\Notifications\Services\NullWhatsAppClient;
use App\Modules\Orders\Events\LatePaymentRefundRequested;
use App\Modules\Orders\Events\OrderCancellationRequested;
use App\Modules\Orders\Events\OrderCancelled;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Payments\Events\PaymentAmountMismatch;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentRefunded;
use App\Modules\Payments\Events\PaymentRefundFailed;
use App\Shared\Providers\ModuleServiceProvider;

final class NotificationsServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [];

    /**
     * Listeners only enqueue notifications (queue `notifications`, afterCommit).
     *
     * @var array<class-string, list<string>>
     */
    protected array $listen = [
        OrderPlaced::class => [S::class.'@onOrderPlaced'],
        OrderPaid::class => [S::class.'@onOrderPaid'],
        PaymentFailed::class => [S::class.'@onPaymentFailed'],
        OrderStatusChanged::class => [S::class.'@onStatusChanged'],
        OrderCancelled::class => [S::class.'@onOrderCancelled'],
        PaymentRefunded::class => [S::class.'@onPaymentRefunded'],
        LatePaymentRefundRequested::class => [S::class.'@onLatePaymentRefund'],
        OrderCancellationRequested::class => [S::class.'@onCancellationRequested'],
        PaymentRefundFailed::class => [S::class.'@onRefundFailed'],
        PaymentAmountMismatch::class => [S::class.'@onAmountMismatch'],
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(WhatsAppClient::class, static fn (): WhatsAppClient => config('whatsapp.driver') === 'log'
            ? new LogWhatsAppClient
            : new NullWhatsAppClient);
    }
}
