<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Trace;

use App\Modules\Shipping\Domain\Config\MethodConfig;
use App\Modules\Shipping\Domain\Config\RuleConfig;
use App\Modules\Shipping\Engine\RuleEvaluation;
use App\Modules\Shipping\Enums\ZoneLocationType;

/** Explanation of one method evaluation (admin simulator, SHIPPING.md §10.1). Mutable by design. */
final class MethodTrace
{
    public string $status = 'unavailable';

    public ?string $reason = null;

    public ?string $detail = null;

    /** @var array{covered: bool, via_zones: list<int>}|null */
    public ?array $coverage = null;

    public ?int $effectiveWeightGrams = null;

    /** @var list<array<string, mixed>> */
    public array $rules = [];

    public ?int $winnerRuleId = null;

    /** @var array<string, mixed>|null */
    public ?array $priceBreakdown = null;

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(public readonly MethodConfig $method) {}

    public function zoneNotMatched(RuleConfig $rule): void
    {
        $this->rules[] = ['rule_id' => $rule->id, 'name' => $rule->name, 'priority' => $rule->priority, 'result' => 'zone_not_matched'];
    }

    public function evaluated(RuleConfig $rule, ZoneLocationType $specificity, RuleEvaluation $evaluation): void
    {
        $row = [
            'rule_id' => $rule->id, 'name' => $rule->name, 'priority' => $rule->priority,
            'specificity' => $specificity->label(), 'result' => $evaluation->matched ? 'matched' : 'rejected',
        ];
        if (! $evaluation->matched) {
            $row['reasons'] = $evaluation->reasonsArray();
        }
        $this->rules[] = $row;
    }

    public function notEvaluated(RuleConfig $rule, ZoneLocationType $specificity): void
    {
        $this->rules[] = ['rule_id' => $rule->id, 'name' => $rule->name, 'priority' => $rule->priority, 'specificity' => $specificity->label(), 'result' => 'not_evaluated'];
    }

    public function warn(string $warning): void
    {
        if (! in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'method_id' => $this->method->id,
            'code' => $this->method->code,
            'name' => $this->method->name,
            'type' => $this->method->type->value,
            'status' => $this->status,
        ];
        if ($this->status === 'unavailable') {
            $data['reason'] = $this->reason;
            $data['detail'] = $this->detail;
        }

        return $data + [
            'coverage' => $this->coverage,
            'effective_weight_grams' => $this->effectiveWeightGrams,
            'weight_basis' => $this->method->weightBasis->value,
            'rules' => $this->rules,
            'winner_rule_id' => $this->winnerRuleId,
            'price_breakdown' => $this->priceBreakdown,
            'warnings' => $this->warnings,
        ];
    }
}
