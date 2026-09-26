<?php

declare(strict_types=1);

namespace App\Modules\Orders\Providers;

use App\Modules\Customers\Contracts\CustomerStatsProvider;
use App\Modules\Inventory\Contracts\MovementReferenceResolver;
use App\Modules\Orders\Console\ExpirePendingOrdersCommand;
use App\Modules\Orders\Contracts\OrderPlacement;
use App\Modules\Orders\Listeners\FlagOrderForReview;
use App\Modules\Orders\Listeners\MarkOrderAsPaid;
use App\Modules\Orders\Listeners\MarkOrderAsRefunded;
use App\Modules\Orders\Listeners\RecordPaymentFailure;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Policies\OrderPolicy;
use App\Modules\Orders\Services\OrderCustomerStatsProvider;
use App\Modules\Orders\Services\OrderMovementReferenceResolver;
use App\Modules\Orders\Services\OrderPaymentContextProvider;
use App\Modules\Orders\Services\OrderPlacementService;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Payments\Contracts\PaymentOrderContextProvider;
use App\Modules\Payments\Events\PaymentAmountMismatch;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\PaymentRefunded;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        OrderPlacement::class => OrderPlacementService::class,
        OrderStateMachine::class => OrderStateMachine::class,
        PaymentOrderContextProvider::class => OrderPaymentContextProvider::class,
        MovementReferenceResolver::class => OrderMovementReferenceResolver::class,
        CustomerStatsProvider::class => OrderCustomerStatsProvider::class,
    ];

    /** @var array<class-string, list<class-string>> Synchronous listeners (same transaction as the payment change). */
    protected array $listen = [
        PaymentApproved::class => [MarkOrderAsPaid::class],
        PaymentFailed::class => [RecordPaymentFailure::class],
        PaymentRefunded::class => [MarkOrderAsRefunded::class],
        PaymentAmountMismatch::class => [FlagOrderForReview::class],
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [Order::class => OrderPolicy::class];

    /** @var list<class-string> */
    protected array $commands = [ExpirePendingOrdersCommand::class];

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('orders:expire-pending')->everyMinute()->onOneServer()->withoutOverlapping();
    }
}
