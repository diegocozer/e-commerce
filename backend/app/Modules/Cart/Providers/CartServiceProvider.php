<?php

declare(strict_types=1);

namespace App\Modules\Cart\Providers;

use App\Modules\Cart\Console\PurgeExpiredCarts;
use App\Modules\Cart\Contracts\CartPresenter;
use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\Services\CartView;
use App\Modules\Cart\Services\EloquentCartService;
use App\Modules\Customers\Contracts\GuestCartMerger;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

final class CartServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        CartService::class => EloquentCartService::class,
        GuestCartMerger::class => EloquentCartService::class,
        CartPresenter::class => CartView::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];

    /** @var list<class-string> */
    protected array $commands = [PurgeExpiredCarts::class];

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('carts:prune')->dailyAt('03:17')->withoutOverlapping()->onOneServer();
    }
}
