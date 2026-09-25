<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine;

use App\Modules\Shipping\Contracts\ShippingEngine;
use App\Modules\Shipping\Domain\Config\ShippingConfigRepository;
use App\Modules\Shipping\Domain\Trace\EvaluationTrace;
use App\Modules\Shipping\Domain\Trace\MethodTrace;
use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\ShippingQuoteResult;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Engine\Handlers\ShippingMethodHandlerInterface;
use App\Modules\Shipping\Enums\UnavailableReason;
use App\Modules\Shipping\Quotes\QuoteHasher;
use App\Modules\Shipping\Support\MonotonicClock;
use Carbon\CarbonImmutable;
use Throwable;

/** Orchestrator (SHIPPING.md §6.2). Deterministic, never persists, never throws for a method. */
final class RuleBasedShippingEngine implements ShippingEngine
{
    /** @var array<string, ShippingMethodHandlerInterface> */
    private array $handlers = [];

    /** @param  iterable<ShippingMethodHandlerInterface>  $handlers */
    public function __construct(
        private readonly ShippingConfigRepository $config,
        private readonly ZoneMatcher $zoneMatcher,
        iterable $handlers,
        private readonly MonotonicClock $clock,
        private readonly QuoteHasher $hasher,
    ) {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->type()->value] = $handler;
        }
    }

    public function quote(ShippingRequest $request): array
    {
        return $this->evaluate($request)->options;
    }

    public function evaluate(ShippingRequest $request, bool $withTrace = false, ?array $onlyMethodIds = null, ?\DateTimeImmutable $at = null): ShippingQuoteResult
    {
        $snapshot = $this->config->snapshot();
        $context = new EvaluationContext($snapshot, $at ?? CarbonImmutable::now(), $this->clock->nowMs());
        $zones = $this->zoneMatcher->match($request->destination, $snapshot->zones);

        $trace = $withTrace ? new EvaluationTrace : null;
        if ($trace !== null) {
            foreach ($zones->byZoneId as $match) {
                $trace->zonesMatched[] = [
                    'zone_id' => $match->zoneId, 'name' => $snapshot->zones[$match->zoneId]->name,
                    'matched_by' => $match->matchedBy, 'specificity' => $match->specificity->label(),
                ];
            }
        }

        $options = [];
        $unavailable = [];
        $acceptsCoupon = [];
        foreach ($snapshot->methods as $method) {
            if ($onlyMethodIds !== null && ! in_array($method->id, $onlyMethodIds, true)) {
                continue;
            }
            $acceptsCoupon[$method->id] = $method->acceptsFreeShippingCoupon;
            $methodTrace = $trace !== null ? new MethodTrace($method) : null;
            $handler = $this->handlers[$method->type->value] ?? null;

            try {
                $result = $handler?->handle($method, $request, $zones, $methodTrace, $context)
                    ?? MethodResult::unavailable($method, UnavailableReason::NoRuleMatched, 'no handler');
            } catch (Throwable $e) {
                report($e);
                $result = MethodResult::unavailable($method, UnavailableReason::NoRuleMatched, 'handler error: '.$e::class);
            }

            if ($result->option !== null) {
                $options[] = $result->option;
            } elseif ($result->unavailable !== null) {
                $unavailable[] = $result->unavailable;
                if ($methodTrace !== null) {
                    $methodTrace->status = 'unavailable';
                    $methodTrace->reason = $result->unavailable->reason->value;
                    $methodTrace->detail = $result->unavailable->detail;
                }
            }
            if ($methodTrace !== null) {
                $trace->methods[] = $methodTrace;
            }
        }

        // Free-shipping coupon (SHIPPING.md §6.3).
        if ($request->couponFreeShipping) {
            $options = array_map(
                static fn (ShippingOption $o): ShippingOption => ($acceptsCoupon[$o->methodId] ?? false) && ! $o->isFree ? $o->withCouponFreeShipping() : $o,
                $options,
            );
        }

        usort($options, static fn (ShippingOption $a, ShippingOption $b): int => [$a->priceCents, $a->deliveryDaysMax, $a->deliveryDaysMin, $a->methodPosition, $a->methodId]
            <=> [$b->priceCents, $b->deliveryDaysMax, $b->deliveryDaysMin, $b->methodPosition, $b->methodId]);

        return new ShippingQuoteResult(null, null, $request->destination, $options, $unavailable, $this->hasher->hash($request, []), $trace);
    }
}
