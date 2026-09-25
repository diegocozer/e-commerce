<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine\Handlers;

use App\Modules\Shipping\Carriers\CarrierRegistry;
use App\Modules\Shipping\Domain\Config\MethodConfig;
use App\Modules\Shipping\Domain\Config\ShippingConfigRepository;
use App\Modules\Shipping\Domain\Trace\MethodTrace;
use App\Modules\Shipping\DTOs\CarrierInfo;
use App\Modules\Shipping\DTOs\CarrierQuote;
use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Engine\DeliveryLabelFormatter;
use App\Modules\Shipping\Engine\EvaluationContext;
use App\Modules\Shipping\Engine\MethodResult;
use App\Modules\Shipping\Engine\ZoneMatchSet;
use App\Modules\Shipping\Enums\FreeShippingReason;
use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Enums\UnavailableReason;
use App\Modules\Shipping\Exceptions\CarrierTimeoutException;
use App\Modules\Shipping\Support\MonotonicClock;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * External carrier method (SHIPPING.md §6.4): one quote() per carrier per
 * evaluation (memoized), total time budget, failures become unavailable
 * options and are logged — never exceptions.
 */
final class CarrierHandler implements ShippingMethodHandlerInterface
{
    public function __construct(
        private readonly CarrierRegistry $registry,
        private readonly ShippingConfigRepository $configRepository,
        private readonly DeliveryLabelFormatter $labels,
        private readonly MonotonicClock $clock,
    ) {}

    public function type(): ShippingMethodType
    {
        return ShippingMethodType::Carrier;
    }

    public function handle(MethodConfig $method, ShippingRequest $request, ZoneMatchSet $zoneMatches, ?MethodTrace $trace, EvaluationContext $context): MethodResult
    {
        $logistics = $request->logistics;
        if ($logistics->hasPickupOnlyItems) {
            return MethodResult::unavailable($method, UnavailableReason::PickupOnlyItems);
        }
        if ($logistics->missingData) {
            return MethodResult::unavailable($method, UnavailableReason::LogisticsDataMissing);
        }

        $cached = $method->carrierId === null ? null : ($context->config->carriers[$method->carrierId] ?? null);
        if ($cached === null || ! $cached->isActive) {
            return MethodResult::unavailable($method, UnavailableReason::CarrierInactive);
        }
        if (! $this->registry->has($cached->driver)) {
            return MethodResult::unavailable($method, UnavailableReason::CarrierNotRegistered, "driver {$cached->driver}");
        }

        try {
            $carrierConfig = $this->configRepository->carrierWithCredentials($cached->carrierId) ?? $cached;
            $carrier = $this->registry->make($carrierConfig);
            if (! $carrier->supports($request)) {
                return MethodResult::unavailable($method, UnavailableReason::CarrierUnsupported);
            }
        } catch (Throwable $e) {
            return MethodResult::unavailable($method, UnavailableReason::CarrierError, self::sanitize($e->getMessage()));
        }

        if (! array_key_exists($cached->carrierId, $context->carrierMemo)) {
            $elapsed = $this->clock->nowMs() - $context->carrierBudgetStartMs;
            if ($elapsed > (int) config('shipping.carrier_total_budget_ms', 10000)) {
                return MethodResult::unavailable($method, UnavailableReason::CarrierBudgetExceeded, "{$elapsed} ms");
            }

            $t0 = $this->clock->nowMs();
            try {
                $context->carrierMemo[$cached->carrierId] = $carrier->quote($request);
                Log::channel('shipping')->info('shipping.carrier.quoted', [
                    'carrier' => $cached->code,
                    'services_count' => count($context->carrierMemo[$cached->carrierId]->services),
                    'duration_ms' => $this->clock->nowMs() - $t0,
                    'cep_prefix' => $request->destination->postalPrefix(),
                    'request_id' => $request->requestId,
                ]);
            } catch (CarrierTimeoutException $e) {
                $context->carrierMemo[$cached->carrierId] = UnavailableReason::CarrierTimeout;
                Log::channel('shipping')->warning('shipping.carrier.failed', [
                    'carrier' => $cached->code, 'reason' => 'timeout', 'duration_ms' => $this->clock->nowMs() - $t0,
                    'cep_prefix' => $request->destination->postalPrefix(), 'request_id' => $request->requestId,
                ]);
            } catch (Throwable $e) {
                $context->carrierMemo[$cached->carrierId] = UnavailableReason::CarrierError;
                Log::channel('shipping')->error('shipping.carrier.failed', [
                    'carrier' => $cached->code, 'reason' => 'error', 'exception' => $e::class,
                    'message' => self::sanitize($e->getMessage()), 'duration_ms' => $this->clock->nowMs() - $t0,
                    'cep_prefix' => $request->destination->postalPrefix(), 'request_id' => $request->requestId,
                ]);
            }
        }

        $quote = $context->carrierMemo[$cached->carrierId];
        if (! $quote instanceof CarrierQuote) {
            return MethodResult::unavailable($method, $quote);
        }

        $service = $method->carrierServiceCode === null ? null : $quote->service($method->carrierServiceCode);
        if ($service === null || $service->error !== null || $service->priceCents === null) {
            return MethodResult::unavailable($method, UnavailableReason::CarrierServiceMissing, $service?->error);
        }

        $min = ($service->deliveryDaysMin ?? $method->deliveryDaysMin) + $method->handlingDays;
        $max = max($min, ($service->deliveryDaysMax ?? $method->deliveryDaysMax) + $method->handlingDays);
        if ($trace !== null) {
            $trace->status = 'option';
            $trace->priceBreakdown = ['price_type' => 'carrier', 'final_cents' => $service->priceCents];
        }

        return MethodResult::option(new ShippingOption(
            optionId: $method->id.':'.$service->serviceCode,
            methodId: $method->id,
            methodCode: $method->code,
            methodType: $method->type,
            name: $method->name,
            description: $method->description,
            priceCents: $service->priceCents,
            originalPriceCents: $service->priceCents,
            isFree: $service->priceCents === 0,
            freeReason: $service->priceCents === 0 ? FreeShippingReason::Rule : null,
            deliveryDaysMin: $min,
            deliveryDaysMax: $max,
            deliveryLabel: $this->labels->delivery($min, $max),
            carrier: new CarrierInfo($cached->code, $cached->name, $service->serviceCode, $service->serviceName),
            pickupAddress: null,
            ruleId: null,
            methodPosition: $method->position,
        ));
    }

    /** Truncated to 300 chars, query strings removed (SHIPPING.md §11). */
    public static function sanitize(string $message): string
    {
        return mb_substr((string) preg_replace('/\?\S*/', '?…', $message), 0, 300);
    }
}
