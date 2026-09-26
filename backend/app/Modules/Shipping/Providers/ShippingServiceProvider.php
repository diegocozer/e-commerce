<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Providers;

use App\Modules\Shipping\Carriers\CarrierRegistry;
use App\Modules\Shipping\Carriers\Fake\FakeCarrier;
use App\Modules\Shipping\Console\PruneShippingQuotesCommand;
use App\Modules\Shipping\Contracts\BusinessDayCalculator;
use App\Modules\Shipping\Contracts\ShippingEngine;
use App\Modules\Shipping\Contracts\ShippingQuoteService;
use App\Modules\Shipping\Contracts\ShippingRequestFactory;
use App\Modules\Shipping\Delivery\WeekendBusinessDayCalculator;
use App\Modules\Shipping\Domain\Config\ShippingConfigRepository;
use App\Modules\Shipping\Domain\Logistics\CartLogisticsCalculator;
use App\Modules\Shipping\DTOs\CarrierConfig;
use App\Modules\Shipping\Engine\Handlers\CarrierHandler;
use App\Modules\Shipping\Engine\Handlers\OwnDeliveryHandler;
use App\Modules\Shipping\Engine\Handlers\PickupHandler;
use App\Modules\Shipping\Engine\Handlers\TableRateHandler;
use App\Modules\Shipping\Engine\RuleBasedShippingEngine;
use App\Modules\Shipping\Engine\ZoneMatcher;
use App\Modules\Shipping\Models\ShippingCarrier;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingRule;
use App\Modules\Shipping\Models\ShippingZone;
use App\Modules\Shipping\Models\ShippingZoneCity;
use App\Modules\Shipping\Models\ShippingZonePostalRange;
use App\Modules\Shipping\Models\ShippingZoneState;
use App\Modules\Shipping\PostalCode\CachedPostalCodeLookup;
use App\Modules\Shipping\PostalCode\FakePostalCodeLookup;
use App\Modules\Shipping\PostalCode\ViaCepPostalCodeLookup;
use App\Modules\Shipping\Quotes\QuoteHasher;
use App\Modules\Shipping\Quotes\ShippingQuoteManager;
use App\Modules\Shipping\Services\DefaultShippingRequestFactory;
use App\Modules\Shipping\Support\MonotonicClock;
use App\Modules\Shipping\Support\SystemMonotonicClock;
use App\Shared\PostalCode\PostalCodeLookup;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

final class ShippingServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, class-string> interface => implementation (singletons) */
    public array $singletons = [
        ShippingEngine::class => RuleBasedShippingEngine::class,
        ShippingRequestFactory::class => DefaultShippingRequestFactory::class,
        ShippingQuoteService::class => ShippingQuoteManager::class,
        MonotonicClock::class => SystemMonotonicClock::class,
        ShippingConfigRepository::class => ShippingConfigRepository::class,
        ZoneMatcher::class => ZoneMatcher::class,
        QuoteHasher::class => QuoteHasher::class,
    ];

    /** @var list<class-string> */
    protected array $commands = [PruneShippingQuotesCommand::class];

    /** Models whose writes invalidate the cached engine configuration. */
    private const array CONFIG_MODELS = [
        ShippingCarrier::class, ShippingMethod::class, ShippingZone::class, ShippingZonePostalRange::class,
        ShippingZoneCity::class, ShippingZoneState::class, ShippingRule::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(RuleBasedShippingEngine::class, fn (Application $app): RuleBasedShippingEngine => new RuleBasedShippingEngine(
            $app->make(ShippingConfigRepository::class),
            $app->make(ZoneMatcher::class),
            [$app->make(PickupHandler::class), $app->make(OwnDeliveryHandler::class), $app->make(TableRateHandler::class), $app->make(CarrierHandler::class)],
            $app->make(MonotonicClock::class),
            $app->make(QuoteHasher::class),
        ));

        $this->app->singleton(CartLogisticsCalculator::class, fn (): CartLogisticsCalculator => new CartLogisticsCalculator((int) config('shipping.roll_margin_cm', 10) * 10));

        $this->app->singleton(CarrierRegistry::class, function (Application $app): CarrierRegistry {
            $registry = new CarrierRegistry;
            $registry->register(
                'fake',
                fn (CarrierConfig $c): FakeCarrier => new FakeCarrier($c, $app->make(MonotonicClock::class)),
                'Transportadora simulada (desenvolvimento)',
                ['mode' => 'string', 'delay_ms' => 'integer', 'max_weight_grams' => 'integer'],
            );

            // $registry->register('melhor_envio', fn (CarrierConfig $c) => new MelhorEnvioCarrier($c, $app->make(HttpFactory::class)));
            return $registry;
        });

        $this->app->singleton(FakePostalCodeLookup::class);
        $this->app->singleton(PostalCodeLookup::class, function (Application $app): PostalCodeLookup {
            if (config('shipping.postal_lookup.driver') === 'fake') {
                return $app->make(FakePostalCodeLookup::class);
            }

            return new CachedPostalCodeLookup(
                new ViaCepPostalCodeLookup(
                    $app->make(HttpFactory::class),
                    (string) config('shipping.postal_lookup.base_url', 'https://viacep.com.br/ws'),
                    (int) config('shipping.postal_lookup.timeout_seconds', 3),
                ),
                $app->make('cache.store'),
                (int) config('shipping.postal_lookup.cache_days', 30),
                (int) config('shipping.postal_lookup.not_found_cache_hours', 24),
            );
        });

        $this->app->singleton(BusinessDayCalculator::class, fn (): WeekendBusinessDayCalculator => new WeekendBusinessDayCalculator(
            (string) config('shipping.timezone', 'America/Sao_Paulo'),
            (string) config('shipping.cutoff_time', '14:00'),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        foreach (self::CONFIG_MODELS as $model) {
            foreach (['saved', 'deleted'] as $event) {
                $model::$event(static function (): void {
                    Cache::forever(ShippingConfigRepository::VERSION_KEY, (int) Cache::get(ShippingConfigRepository::VERSION_KEY, 1) + 1);
                });
            }
        }
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('shipping:prune-quotes')->dailyAt('03:20')->timezone('America/Sao_Paulo')->onOneServer()->withoutOverlapping();
    }
}
