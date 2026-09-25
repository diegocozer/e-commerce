<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Pricing\Contracts\PriceTierTable;
use App\Modules\Pricing\DTOs\PriceContext;
use App\Modules\Pricing\DTOs\PriceQuote;
use App\Modules\Pricing\DTOs\TierPrice;
use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Pricing\Models\PriceList;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ADR-005 / RN-PRC / DATABASE.md §3.2: every applicable candidate is computed
 * independently on the reference price (base with quantity tiers) and the
 * LOWEST wins. Tie → label priority customer_price > price_list > promotion >
 * variant_promo > tier > base. Never below 1 cent.
 */
final class DatabasePriceResolver implements PriceResolver, PriceTierTable
{
    private const array PRIORITY = [
        PriceSource::CustomerPrice->value => 0,
        PriceSource::PriceList->value => 1,
        PriceSource::Promotion->value => 2,
        PriceSource::VariantPromo->value => 3,
        PriceSource::Tier->value => 4,
        PriceSource::Base->value => 5,
    ];

    public function resolve(PriceContext $ctx): PriceQuote
    {
        return $this->resolveMany([$ctx])[0];
    }

    public function resolveMany(array $contexts): array
    {
        if ($contexts === []) {
            return [];
        }
        $data = $this->load($contexts);

        return array_map(fn (PriceContext $ctx): PriceQuote => $this->quote($ctx, $data), array_values($contexts));
    }

    public function tiersFor(PriceContext $ctx, Quantity $minimum): array
    {
        $data = $this->load([$ctx]);
        $variantId = $ctx->subject->variantId;
        $profile = $data['profiles'][$ctx->customerId ?? 0] ?? null;

        $breakpoints = [$minimum->milli() => $minimum];
        foreach ($data['baseTiers'][$variantId] ?? [] as [$min]) {
            $breakpoints[$min->milli()] = $min;
        }
        $listId = $profile['price_list_id'] ?? null;
        foreach ($listId !== null ? ($data['listTiers'][$listId][$variantId] ?? []) : [] as [$min]) {
            $breakpoints[$min->milli()] = $min;
        }
        ksort($breakpoints);

        $rows = [];
        $previous = null;
        foreach ($breakpoints as $min) {
            if ($min->lessThan($minimum)) {
                continue;
            }
            $quote = $this->quote(new PriceContext($ctx->subject, $min, $min, $ctx->customerId, $ctx->at), $data);
            if ($previous !== null && $previous->unitPrice->equals($quote->unitPrice) && $previous->source === $quote->source) {
                continue;
            }
            $rows[] = new TierPrice($min, $quote->unitPrice, $quote->source);
            $previous = $quote;
        }

        return count($rows) > 1 ? $rows : [];
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    private function quote(PriceContext $ctx, array $data): PriceQuote
    {
        $s = $ctx->subject;
        $at = $ctx->at;
        $q = $ctx->effectiveTierQuantity();
        $base = $s->basePrice;

        /** @var list<array{price: Money, source: PriceSource, label: ?string, promotion: ?array<string, mixed>, list: ?int}> $candidates */
        $candidates = [['price' => $base, 'source' => PriceSource::Base, 'label' => null, 'promotion' => null, 'list' => null]];

        $reference = $base;
        $tier = self::tierFor($data['baseTiers'][$s->variantId] ?? [], $q);
        if ($tier !== null) {
            $reference = $tier;
            $candidates[] = ['price' => $tier, 'source' => PriceSource::Tier, 'label' => 'Preço por quantidade', 'promotion' => null, 'list' => null];
        }

        $profile = $data['profiles'][$ctx->customerId ?? 0] ?? null;
        $listId = $profile['price_list_id'] ?? null;
        if ($listId !== null && isset($data['lists'][$listId])) {
            $list = $data['lists'][$listId];
            $listPrice = self::tierFor($data['listTiers'][$listId][$s->variantId] ?? [], $q);
            if ($listPrice === null && $list['discount_bp'] !== null) {
                $listPrice = $base->discountByBasisPoints($list['discount_bp']);
            }
            if ($listPrice !== null) {
                $candidates[] = ['price' => $listPrice, 'source' => PriceSource::PriceList, 'label' => 'Preço '.$list['name'], 'promotion' => null, 'list' => $listId];
            }
        }

        if ($s->promoPrice !== null
            && ($s->promoStartsAt === null || $s->promoStartsAt->lessThanOrEqualTo($at))
            && ($s->promoEndsAt === null || $s->promoEndsAt->greaterThan($at))) {
            $candidates[] = ['price' => $s->promoPrice, 'source' => PriceSource::VariantPromo, 'label' => 'Promoção', 'promotion' => ['id' => null, 'name' => 'Promoção', 'ends_at' => $s->promoEndsAt], 'list' => null];
        }

        foreach ($data['promotions'] as $promotion) {
            if (! self::promotionApplies($promotion, $s->productId, $s->brandId, $s->categoryIdsWithAncestors, $at)) {
                continue;
            }
            $price = $promotion['discount_type'] === 'percent'
                ? $reference->discountByBasisPoints(min(10000, $promotion['value']))
                : $reference->subtract(Money::ofCents($promotion['value']));
            $candidates[] = ['price' => $price, 'source' => PriceSource::Promotion, 'label' => $promotion['name'], 'promotion' => $promotion, 'list' => null];
        }

        if ($profile !== null) {
            foreach ($data['customerPrices'][$s->variantId] ?? [] as $cp) {
                $owner = ($cp['customer_id'] !== null && $cp['customer_id'] === $profile['customer_id'])
                    || ($cp['company_id'] !== null && $cp['company_id'] === $profile['company_id']);
                if ($owner && ($cp['starts_at'] === null || $cp['starts_at']->lessThanOrEqualTo($at))
                    && ($cp['ends_at'] === null || $cp['ends_at']->greaterThan($at))) {
                    $candidates[] = ['price' => Money::ofCents($cp['price_cents']), 'source' => PriceSource::CustomerPrice, 'label' => 'Seu preço', 'promotion' => null, 'list' => null];
                }
            }
        }

        $best = null;
        foreach ($candidates as $candidate) {
            $candidate['price'] = Money::max($candidate['price'], Money::ofCents(1)); // RN-PRC-011
            if ($best === null
                || $candidate['price']->lessThan($best['price'])
                || ($candidate['price']->equals($best['price']) && self::PRIORITY[$candidate['source']->value] < self::PRIORITY[$best['source']->value])) {
                $best = $candidate;
            }
        }

        return new PriceQuote(
            variantId: $s->variantId,
            unitPrice: $best['price'],
            baseUnitPrice: $base,
            lineTotal: $best['price']->multiplyByQuantity($ctx->billableQuantity),
            billableQuantity: $ctx->billableQuantity,
            source: $best['source'],
            sourceLabel: $best['label'],
            promotionId: $best['source'] === PriceSource::Promotion ? $best['promotion']['id'] : null,
            priceListId: $best['list'],
            promotionName: $best['promotion']['name'] ?? null,
            promotionEndsAt: $best['promotion']['ends_at'] ?? null,
        );
    }

    /** @param  list<array{0: Quantity, 1: Money}>  $tiers  ordered by min asc */
    private static function tierFor(array $tiers, Quantity $q): ?Money
    {
        $price = null;
        foreach ($tiers as [$min, $money]) {
            if ($min->lessThanOrEqual($q)) {
                $price = $money;
            }
        }

        return $price;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  list<int>  $categoryIds
     */
    private static function promotionApplies(array $p, int $productId, ?int $brandId, array $categoryIds, CarbonImmutable $at): bool
    {
        if ($p['starts_at']->greaterThan($at) || ($p['ends_at'] !== null && $p['ends_at']->lessThanOrEqualTo($at))) {
            return false;
        }
        if ($p['scope'] === 'all') {
            return true;
        }

        return in_array($productId, $p['products'], true)
            || ($brandId !== null && in_array($brandId, $p['brands'], true))
            || array_intersect($categoryIds, $p['categories']) !== [];
    }

    /**
     * Loads every table needed for the contexts in a constant number of queries.
     *
     * @param  list<PriceContext>  $contexts
     * @return array<string, mixed>
     */
    private function load(array $contexts): array
    {
        $variantIds = array_values(array_unique(array_map(fn (PriceContext $c) => $c->subject->variantId, $contexts)));
        $customerIds = array_values(array_unique(array_filter(array_map(fn (PriceContext $c) => $c->customerId, $contexts))));
        $minAt = $maxAt = $contexts[0]->at;
        foreach ($contexts as $c) {
            $minAt = $c->at->lessThan($minAt) ? $c->at : $minAt;
            $maxAt = $c->at->greaterThan($maxAt) ? $c->at : $maxAt;
        }

        // Effective price list (DB-07): customer → company → default. The default does not add a candidate.
        $profiles = [];
        $default = PriceList::query()->where('is_default', true)->value('id');
        if ($customerIds !== []) {
            $rows = Customer::query()
                ->leftJoin('companies', 'companies.id', '=', 'customers.company_id')
                ->whereIn('customers.id', $customerIds)
                ->get(['customers.id', 'customers.company_id', 'customers.price_list_id', 'companies.price_list_id as company_price_list_id']);
            foreach ($rows as $row) {
                $listId = $row->price_list_id ?? $row->getAttribute('company_price_list_id') ?? $default;
                $profiles[(int) $row->id] = [
                    'customer_id' => (int) $row->id,
                    'company_id' => $row->company_id !== null ? (int) $row->company_id : null,
                    'price_list_id' => $listId !== null && (int) $listId !== (int) $default ? (int) $listId : null,
                ];
            }
        }

        $listIds = array_values(array_unique(array_filter(array_column($profiles, 'price_list_id'))));
        $lists = [];
        foreach ($listIds === [] ? [] : DB::table('price_lists')->whereIn('id', $listIds)->where('is_active', true)->get() as $l) {
            $lists[(int) $l->id] = ['name' => $l->name, 'discount_bp' => $l->discount_bp !== null ? (int) $l->discount_bp : null];
        }

        $baseTiers = [];
        $listTiers = [];
        $tierRows = DB::table('price_tiers')
            ->whereIn('variant_id', $variantIds)
            ->where(fn ($q) => $q->whereNull('price_list_id')->orWhereIn('price_list_id', array_keys($lists) ?: [0]))
            ->orderBy('min_quantity')
            ->get();
        foreach ($tierRows as $t) {
            $entry = [Quantity::fromString((string) $t->min_quantity), Money::ofCents((int) $t->price_cents)];
            if ($t->price_list_id === null) {
                $baseTiers[(int) $t->variant_id][] = $entry;
            } else {
                $listTiers[(int) $t->price_list_id][(int) $t->variant_id][] = $entry;
            }
        }

        $customerPrices = [];
        if ($profiles !== []) {
            $companyIds = array_values(array_filter(array_column($profiles, 'company_id')));
            $cpRows = DB::table('customer_prices')
                ->whereIn('variant_id', $variantIds)
                ->where(fn ($q) => $q->whereIn('customer_id', array_keys($profiles))->orWhereIn('company_id', $companyIds ?: [0]))
                ->get();
            foreach ($cpRows as $cp) {
                $customerPrices[(int) $cp->variant_id][] = [
                    'customer_id' => $cp->customer_id !== null ? (int) $cp->customer_id : null,
                    'company_id' => $cp->company_id !== null ? (int) $cp->company_id : null,
                    'price_cents' => (int) $cp->price_cents,
                    'starts_at' => $cp->starts_at !== null ? CarbonImmutable::parse($cp->starts_at) : null,
                    'ends_at' => $cp->ends_at !== null ? CarbonImmutable::parse($cp->ends_at) : null,
                ];
            }
        }

        return [
            'profiles' => $profiles,
            'lists' => $lists,
            'baseTiers' => $baseTiers,
            'listTiers' => $listTiers,
            'customerPrices' => $customerPrices,
            'promotions' => $this->promotions($minAt, $maxAt),
        ];
    }

    /** @return list<array<string, mixed>> active promotions overlapping [minAt, maxAt] with their targets */
    private function promotions(CarbonImmutable $minAt, CarbonImmutable $maxAt): array
    {
        $rows = DB::table('promotions')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('starts_at', '<=', $maxAt)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $minAt))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->all();
        $targets = [];
        foreach (['products' => ['promotion_products', 'product_id'], 'categories' => ['promotion_categories', 'category_id'], 'brands' => ['promotion_brands', 'brand_id']] as $kind => [$table, $column]) {
            foreach (DB::table($table)->whereIn('promotion_id', $ids)->get() as $t) {
                $targets[(int) $t->promotion_id][$kind][] = (int) $t->{$column};
            }
        }

        $out = [];
        foreach ($rows as $p) {
            $out[] = [
                'id' => (int) $p->id,
                'name' => $p->name,
                'discount_type' => $p->discount_type,
                'value' => (int) $p->value,
                'scope' => $p->scope,
                'starts_at' => CarbonImmutable::parse($p->starts_at),
                'ends_at' => $p->ends_at !== null ? CarbonImmutable::parse($p->ends_at) : null,
                'products' => $targets[(int) $p->id]['products'] ?? [],
                'categories' => $targets[(int) $p->id]['categories'] ?? [],
                'brands' => $targets[(int) $p->id]['brands'] ?? [],
            ];
        }

        return $out;
    }
}
