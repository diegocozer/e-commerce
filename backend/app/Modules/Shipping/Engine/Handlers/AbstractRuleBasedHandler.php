<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine\Handlers;

use App\Modules\Shipping\Domain\Config\MethodConfig;
use App\Modules\Shipping\Domain\Config\RuleConfig;
use App\Modules\Shipping\Domain\Trace\MethodTrace;
use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Engine\DeliveryLabelFormatter;
use App\Modules\Shipping\Engine\EvaluationContext;
use App\Modules\Shipping\Engine\MethodResult;
use App\Modules\Shipping\Engine\RuleEvaluator;
use App\Modules\Shipping\Engine\RulePriceCalculator;
use App\Modules\Shipping\Engine\ZoneMatchSet;
use App\Modules\Shipping\Enums\FreeShippingReason;
use App\Modules\Shipping\Enums\RejectionCode;
use App\Modules\Shipping\Enums\ShippingPriceType;
use App\Modules\Shipping\Enums\UnavailableReason;
use App\Modules\Shipping\Enums\ZoneLocationType;

/**
 * Zones + rules (SHIPPING.md §6.2): coverage, candidates sorted by
 * priority ASC → specificity DESC → id ASC, first matching rule wins.
 */
abstract class AbstractRuleBasedHandler implements ShippingMethodHandlerInterface
{
    public function __construct(
        private readonly RuleEvaluator $evaluator,
        private readonly RulePriceCalculator $prices,
        private readonly DeliveryLabelFormatter $labels,
    ) {}

    public function handle(MethodConfig $method, ShippingRequest $request, ZoneMatchSet $zoneMatches, ?MethodTrace $trace, EvaluationContext $context): MethodResult
    {
        $logistics = $request->logistics;
        if ($logistics->hasPickupOnlyItems) {
            return MethodResult::unavailable($method, UnavailableReason::PickupOnlyItems);
        }
        if ($logistics->missingData) {
            return MethodResult::unavailable($method, UnavailableReason::LogisticsDataMissing, 'variantes: '.implode(',', $logistics->variantsMissingData));
        }

        $config = $context->config;
        $now = $context->now;
        $rules = $config->rulesOf($method->id);
        $zonedRules = array_values(array_filter($rules, static fn (RuleConfig $r): bool => $r->zoneId !== null && isset($config->zones[$r->zoneId])));

        // --- coverage ---
        $viaZones = [];
        foreach ($zonedRules as $rule) {
            if ($zoneMatches->has((int) $rule->zoneId) && $rule->isValidAt($now)) {
                $viaZones[] = (int) $rule->zoneId;
            }
        }
        $covered = $zonedRules === [] || $viaZones !== [];
        if ($trace !== null) {
            $trace->coverage = ['covered' => $covered, 'via_zones' => array_values(array_unique($viaZones))];
        }
        if (! $covered) {
            if (! $request->destination->resolved) {
                foreach ($zonedRules as $rule) {
                    if ($config->zones[$rule->zoneId]->hasCities()) {
                        return MethodResult::unavailable($method, UnavailableReason::DestinationUnresolved);
                    }
                }
            }

            return MethodResult::unavailable($method, UnavailableReason::OutOfCoverage);
        }

        // --- candidates ---
        $candidates = [];
        foreach ($rules as $rule) {
            if ($rule->zoneId === null) {
                $spec = ZoneLocationType::Global;
            } elseif ($zoneMatches->has($rule->zoneId)) {
                $spec = $zoneMatches->get($rule->zoneId)->specificity;
            } else {
                $trace?->zoneNotMatched($rule);

                continue;
            }
            $candidates[] = [$rule, $spec];
        }
        usort($candidates, static fn (array $a, array $b): int => [$a[0]->priority, -$a[1]->value, $a[0]->id] <=> [$b[0]->priority, -$b[1]->value, $b[0]->id]);

        $weight = $logistics->effectiveWeightGrams($method->weightBasis, $method->cubicDivisor ?? $config->defaultCubicDivisor);
        if ($trace !== null) {
            $trace->effectiveWeightGrams = $weight;
            $this->warnings($trace, $candidates, $zonedRules);
        }

        $winnerIndex = null;
        $rejections = [];
        foreach ($candidates as $i => [$rule, $spec]) {
            $evaluation = $this->evaluator->evaluate($rule, $request, $weight, $now);
            $trace?->evaluated($rule, $spec, $evaluation);
            if ($evaluation->matched) {
                $winnerIndex = $i;
                break;
            }
            $rejections[] = $evaluation;
        }

        if ($winnerIndex === null) {
            return MethodResult::unavailable($method, $this->unmatchedReason($rejections), "peso {$weight} g");
        }

        /** @var RuleConfig $winner */
        [$winner, $winnerSpec] = $candidates[$winnerIndex];
        if ($trace !== null) {
            foreach (array_slice($candidates, $winnerIndex + 1) as [$rule, $spec]) {
                $trace->notEvaluated($rule, $spec);
            }
            // Tie between the winner and the next candidate (same priority and specificity).
            $next = $candidates[$winnerIndex + 1] ?? null;
            $prev = $candidates[$winnerIndex - 1] ?? null;
            foreach ([$next, $prev] as $other) {
                if ($other !== null && $other[0]->priority === $winner->priority && $other[1] === $winnerSpec) {
                    $trace->warn('tie_broken_by_id');
                }
            }
        }

        $breakdown = $this->prices->breakdown($winner, $weight, $request->subtotalCents);
        $price = $breakdown['final_cents'];

        if ($winner->priceType === ShippingPriceType::Free) {
            $original = 0;
            foreach (array_slice($candidates, $winnerIndex + 1) as [$rule]) {
                if ($rule->priceType !== ShippingPriceType::Free && $this->evaluator->evaluate($rule, $request, $weight, $now)->matched) {
                    $original = $this->prices->price($rule, $weight, $request->subtotalCents);
                    break;
                }
            }
            $isFree = true;
            $freeReason = FreeShippingReason::Rule;
        } else {
            $original = $price;
            $isFree = $price === 0;
            $freeReason = $isFree ? FreeShippingReason::Rule : null;
        }

        if ($trace !== null) {
            $trace->status = 'option';
            $trace->winnerRuleId = $winner->id;
            $trace->priceBreakdown = $breakdown + ['original_price_cents' => $original];
        }

        $daysMin = $winner->deliveryDaysMin ?? $method->deliveryDaysMin;
        $daysMax = max($daysMin, $winner->deliveryDaysMax ?? $method->deliveryDaysMax);

        return MethodResult::option(new ShippingOption(
            optionId: $method->id.':'.$winner->id,
            methodId: $method->id,
            methodCode: $method->code,
            methodType: $method->type,
            name: $method->name,
            description: $method->description,
            priceCents: $price,
            originalPriceCents: $original,
            isFree: $isFree,
            freeReason: $freeReason,
            deliveryDaysMin: $daysMin,
            deliveryDaysMax: $daysMax,
            deliveryLabel: $this->labels->delivery($daysMin, $daysMax),
            carrier: null,
            pickupAddress: null,
            ruleId: $winner->id,
            methodPosition: $method->position,
        ));
    }

    /** @param  list<\App\Modules\Shipping\Engine\RuleEvaluation>  $rejections */
    private function unmatchedReason(array $rejections): UnavailableReason
    {
        $relevant = array_values(array_filter($rejections, static fn ($e): bool => ! $e->isTemporal()));
        if ($relevant === []) {
            return UnavailableReason::NoRuleMatched;
        }

        $subsetOf = static function (array $allowed) use ($relevant): bool {
            foreach ($relevant as $evaluation) {
                foreach ($evaluation->codes() as $code) {
                    if (! in_array($code, $allowed, true)) {
                        return false;
                    }
                }
            }

            return true;
        };

        if ($subsetOf([RejectionCode::WeightAboveMax])) {
            return UnavailableReason::WeightAboveLimit;
        }
        if ($subsetOf([RejectionCode::WeightAboveMax, RejectionCode::VolumeAboveMax, RejectionCode::LengthAboveMax])) {
            return UnavailableReason::VolumeAboveLimit;
        }

        return UnavailableReason::NoRuleMatched;
    }

    /**
     * Configuration warnings for the simulator (SHIPPING.md §10.1).
     *
     * @param  list<array{0: RuleConfig, 1: ZoneLocationType}>  $candidates
     * @param  list<RuleConfig>  $zonedRules
     */
    private function warnings(MethodTrace $trace, array $candidates, array $zonedRules): void
    {
        foreach ($candidates as $i => [$rule, $spec]) {
            if ($rule->priceType === ShippingPriceType::Free && $rule->zoneId === null && $zonedRules === []) {
                $trace->warn('free_rule_without_coverage_limit');
            }
            if ($rule->isUnconditional() && $rule->isActive && isset($candidates[$i + 1])) {
                $trace->warn('rule_never_reachable');
            }
        }
    }
}
