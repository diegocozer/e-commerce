<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Shipping\Domain\Config\ShippingConfigRepository;
use App\Modules\Shipping\Enums\ShippingPriceType;
use App\Modules\Shipping\Models\ShippingRule;
use App\Shared\Domain\ActorRef;
use Illuminate\Support\Facades\DB;

final class ManageRules
{
    private const array FIELDS = [
        'method_id', 'zone_id', 'name', 'priority', 'min_weight_grams', 'max_weight_grams', 'min_subtotal_cents',
        'max_subtotal_cents', 'min_volume_cm3', 'max_volume_cm3', 'max_package_length_cm', 'price_type', 'price_cents',
        'per_kg_cents', 'percentage_bp', 'min_price_cents', 'max_price_cents', 'delivery_days_min', 'delivery_days_max',
        'valid_from', 'valid_until', 'is_active',
    ];

    public function __construct(private readonly ShippingAdminSupport $support) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ActorRef $actor): ShippingRule
    {
        return DB::transaction(function () use ($data, $actor): ShippingRule {
            $rule = ShippingRule::query()->create(array_intersect_key($data, array_flip(self::FIELDS)) + ['price_cents' => 0, 'per_kg_cents' => 0, 'percentage_bp' => 0])->refresh();
            $this->support->record($actor, 'shipping_rule', 'created', $rule->id, [], ShippingAdminSupport::snapshot($rule));

            return $rule;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(ShippingRule $rule, array $data, ActorRef $actor): ShippingRule
    {
        return DB::transaction(function () use ($rule, $data, $actor): ShippingRule {
            $rule = ShippingRule::query()->lockForUpdate()->findOrFail($rule->id);
            $this->support->assertFresh($rule, $data['expected_updated_at'] ?? null);
            $before = ShippingAdminSupport::snapshot($rule);
            $rule->update(array_intersect_key($data, array_flip(self::FIELDS)));
            $this->support->record($actor, 'shipping_rule', 'updated', $rule->id, $before, ShippingAdminSupport::snapshot($rule));

            return $rule;
        });
    }

    public function delete(ShippingRule $rule, ActorRef $actor): void
    {
        DB::transaction(function () use ($rule, $actor): void {
            $before = ShippingAdminSupport::snapshot($rule);
            $rule->delete();
            $this->support->record($actor, 'shipping_rule', 'deleted', $rule->id, $before, []);
        });
    }

    public function duplicate(ShippingRule $rule, ActorRef $actor): ShippingRule
    {
        return DB::transaction(function () use ($rule, $actor): ShippingRule {
            $copy = $rule->replicate(['created_at', 'updated_at', 'deleted_at']);
            $copy->name = mb_substr($rule->name.' (cópia)', 0, 150);
            $copy->is_active = false;
            $copy->save();
            $this->support->record($actor, 'shipping_rule', 'duplicated', $copy->id, [], ShippingAdminSupport::snapshot($copy) + ['source_rule_id' => $rule->id]);

            return $copy;
        });
    }

    /**
     * priority = (index + 1) × 10 inside the method/zone group.
     *
     * @param  list<int>  $ids
     * @return list<ShippingRule>
     */
    public function reorder(int $methodId, ?int $zoneId, array $ids, ActorRef $actor): array
    {
        return DB::transaction(function () use ($methodId, $zoneId, $ids, $actor): array {
            $rules = ShippingRule::query()->where('method_id', $methodId)
                ->when($zoneId === null, fn ($q) => $q->whereNull('zone_id'), fn ($q) => $q->where('zone_id', $zoneId))
                ->whereIn('id', $ids)->get()->keyBy('id');
            foreach (array_values($ids) as $index => $id) {
                $rules[$id]?->update(['priority' => ($index + 1) * 10]);
            }
            $this->support->record($actor, 'shipping_rule', 'reordered', $methodId, [], ['zone_id' => $zoneId, 'ids' => array_values($ids)]);

            return ShippingRule::query()->whereIn('id', $ids)->orderBy('priority')->orderBy('id')->get()->all();
        });
    }

    /**
     * Configuration warnings for the saved rule (API.md §3.G.11).
     *
     * @return list<string>
     */
    public function warnings(ShippingRule $rule): array
    {
        $warnings = [];
        $siblings = ShippingRule::query()->where('method_id', $rule->method_id)->where('is_active', true)->where('id', '!=', $rule->id)->get();

        if ($rule->is_active && $siblings->contains(fn (ShippingRule $r): bool => $r->priority === $rule->priority && $r->zone_id === $rule->zone_id)) {
            $warnings[] = 'tie_broken_by_id';
        }
        if ($rule->price_type === ShippingPriceType::Free && $rule->zone_id === null
            && ! ShippingRule::query()->where('method_id', $rule->method_id)->whereNotNull('zone_id')->exists()) {
            $warnings[] = 'free_rule_without_coverage_limit';
        }
        $blocker = $siblings->first(fn (ShippingRule $r): bool => $r->priority < $rule->priority && ($r->zone_id === null || $r->zone_id === $rule->zone_id)
            && ShippingConfigRepository::rule($r)->isUnconditional());
        if ($blocker !== null) {
            $warnings[] = 'rule_never_reachable';
        }

        return $warnings;
    }
}
