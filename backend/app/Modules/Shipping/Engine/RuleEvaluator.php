<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine;

use App\Modules\Shipping\Domain\Config\RuleConfig;
use App\Modules\Shipping\Domain\Logistics\Dimension;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Enums\RejectionCode;

/**
 * Evaluates one rule against the request (zone already filtered). All limits
 * are inclusive; valid_from inclusive, valid_until exclusive (SHIPPING.md §4.7).
 * Collects every violated condition, not only the first.
 */
final class RuleEvaluator
{
    public function evaluate(RuleConfig $rule, ShippingRequest $request, int $effectiveWeightGrams, \DateTimeImmutable $now): RuleEvaluation
    {
        $reasons = [];
        $add = static function (RejectionCode $code, string $detail) use (&$reasons): void {
            $reasons[] = ['code' => $code, 'detail' => $detail];
        };

        if (! $rule->isActive) {
            $add(RejectionCode::Inactive, 'regra inativa');
        }
        if ($rule->validFrom !== null && $now < $rule->validFrom) {
            $add(RejectionCode::NotYetValid, 'vigente a partir de '.$rule->validFrom->format(DATE_ATOM));
        }
        if ($rule->validUntil !== null && $now >= $rule->validUntil) {
            $add(RejectionCode::Expired, 'vigente até '.$rule->validUntil->format(DATE_ATOM));
        }

        $w = $effectiveWeightGrams;
        if ($rule->minWeightGrams !== null && $w < $rule->minWeightGrams) {
            $add(RejectionCode::WeightBelowMin, "{$w} g < {$rule->minWeightGrams} g");
        }
        if ($rule->maxWeightGrams !== null && $w > $rule->maxWeightGrams) {
            $add(RejectionCode::WeightAboveMax, "{$w} g > {$rule->maxWeightGrams} g");
        }

        $s = $request->subtotalCents;
        if ($rule->minSubtotalCents !== null && $s < $rule->minSubtotalCents) {
            $add(RejectionCode::SubtotalBelowMin, "{$s} < {$rule->minSubtotalCents}");
        }
        if ($rule->maxSubtotalCents !== null && $s > $rule->maxSubtotalCents) {
            $add(RejectionCode::SubtotalAboveMax, "{$s} > {$rule->maxSubtotalCents}");
        }

        $v = $request->logistics->totalVolumeCm3;
        if ($rule->minVolumeCm3 !== null && $v < $rule->minVolumeCm3) {
            $add(RejectionCode::VolumeBelowMin, "{$v} cm³ < {$rule->minVolumeCm3} cm³");
        }
        if ($rule->maxVolumeCm3 !== null && $v > $rule->maxVolumeCm3) {
            $add(RejectionCode::VolumeAboveMax, "{$v} cm³ > {$rule->maxVolumeCm3} cm³");
        }

        $l = $request->logistics->largestDimensionMm;
        if ($rule->maxPackageLengthMm !== null && $l > $rule->maxPackageLengthMm) {
            $add(RejectionCode::LengthAboveMax, Dimension::mmToCm($l).' cm > '.Dimension::mmToCm($rule->maxPackageLengthMm).' cm');
        }

        return new RuleEvaluation($reasons === [], $reasons);
    }
}
