<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Console\PruneWebhooksCommand;
use App\Modules\Payments\Console\ReconcilePaymentsCommand;
use App\Modules\Payments\Console\RetryUnprocessedWebhooksCommand;
use App\Modules\Payments\Console\SandboxApproveCommand;
use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Contracts\PaymentService;
use App\Modules\Payments\Contracts\PaymentWebhookVerifier;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Services\DefaultPaymentService;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Modules\Payments\Services\WebhookVerifierFactory;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        PaymentGatewayManager::class => PaymentGatewayManager::class,
        WebhookVerifierFactory::class => WebhookVerifierFactory::class,
        DefaultPaymentService::class => DefaultPaymentService::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];

    /** @var list<class-string> */
    protected array $commands = [
        ReconcilePaymentsCommand::class,
        RetryUnprocessedWebhooksCommand::class,
        PruneWebhooksCommand::class,
        SandboxApproveCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->alias(DefaultPaymentService::class, PaymentService::class);
        $this->app->bind(PaymentGatewayInterface::class, fn ($app): PaymentGatewayInterface => $app->make(PaymentGatewayManager::class)->gateway());
        $this->app->bind(PaymentWebhookVerifier::class, fn ($app): PaymentWebhookVerifier => $app->make(WebhookVerifierFactory::class)
            ->for(PaymentProvider::from((string) config('payments.driver', 'sandbox'))));
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('payments:reconcile')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
        $schedule->command('webhooks:retry-unprocessed')->everyTenMinutes()->onOneServer()->withoutOverlapping();
        $schedule->command('webhooks:prune')->weeklyOn(0, '04:00')->timezone('America/Sao_Paulo')->onOneServer()->withoutOverlapping();
    }
}
