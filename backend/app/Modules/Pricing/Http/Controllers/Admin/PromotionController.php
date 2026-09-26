<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Admin;

use App\Modules\Pricing\Enums\PromotionDiscountType;
use App\Modules\Pricing\Enums\PromotionScope;
use App\Modules\Pricing\Exceptions\PricingConflict;
use App\Modules\Pricing\Http\Resources\PricingPresenter as P;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Pricing\Support\RecordsAudit;
use App\Shared\Domain\Money;
use App\Shared\Support\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PromotionController
{
    use RecordsAudit;

    private const array AUDITED = ['name', 'discount_type', 'value', 'scope', 'starts_at', 'ends_at', 'is_active', 'priority'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['scheduled', 'active', 'ended', 'inactive'])],
            'sort' => ['sometimes', Rule::in(['starts_at', '-starts_at', 'name'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $now = now();
        $query = Promotion::query()
            ->when(isset($f['q']), fn ($q) => $q->whereRaw('public.f_unaccent(name) ILIKE public.f_unaccent(?)', ['%'.$f['q'].'%']));
        match ($f['status'] ?? null) {
            'inactive' => $query->where('is_active', false),
            'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $now),
            'ended' => $query->where('is_active', true)->whereNotNull('ends_at')->where('ends_at', '<=', $now),
            'active' => $query->current($now),
            default => null,
        };
        $sort = $f['sort'] ?? '-starts_at';
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');
        $page = $query->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return new JsonResponse(P::paginated($page, $page->getCollection()->map(fn (Promotion $p) => P::promotion($p))->all()));
    }

    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['data' => P::promotion(Promotion::query()->findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => P::promotion($this->save(new Promotion, $request))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return new JsonResponse(['data' => P::promotion($this->save(Promotion::query()->findOrFail($id), $request))]);
    }

    public function destroy(int $id): Response
    {
        $promotion = Promotion::query()->findOrFail($id);
        $promotion->delete();
        $this->audit('promotion.deleted', 'promotion', $promotion->id, ['name' => $promotion->name], []);

        return response()->noContent();
    }

    /** POST /admin/promotions/{id}/preview — base price vs price with this promotion. */
    public function preview(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::query()->findOrFail($id);
        $data = $request->validate(['variant_ids' => ['required', 'array', 'min:1', 'max:20'], 'variant_ids.*' => ['integer', 'distinct']]);
        $rows = DB::table('product_variants')->whereIn('id', $data['variant_ids'])->whereNull('deleted_at')->orderBy('sku')->get(['id', 'sku', 'price_cents']);

        return new JsonResponse(['data' => $rows->map(function ($v) use ($promotion) {
            $base = Money::ofCents((int) $v->price_cents);
            $promo = $promotion->discount_type === PromotionDiscountType::Percent
                ? $base->discountByBasisPoints(min(10000, $promotion->value))
                : Money::max($base->subtract(Money::ofCents($promotion->value)), Money::ofCents(1));

            return ['variant_id' => (int) $v->id, 'sku' => $v->sku, 'base_price_cents' => $base->cents(), 'promo_price_cents' => max(1, $promo->cents())];
        })->values()]);
    }

    private function save(Promotion $promotion, Request $request): Promotion
    {
        $creating = ! $promotion->exists;
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'discount_type' => [$req, Rule::enum(PromotionDiscountType::class)],
            'value' => [$req, 'integer', 'min:1'],
            'scope' => [$req, Rule::enum(PromotionScope::class)],
            'starts_at' => [$req, 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'product_ids' => ['sometimes', 'array'], 'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')],
            'category_ids' => ['sometimes', 'array'], 'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            'brand_ids' => ['sometimes', 'array'], 'brand_ids.*' => ['integer', 'distinct', Rule::exists('brands', 'id')],
            'expected_updated_at' => ['sometimes', 'date'],
        ]);

        return DB::transaction(function () use ($promotion, $data, $creating) {
            if (! $creating) {
                $promotion = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
                PricingConflict::checkStale($data['expected_updated_at'] ?? null, $promotion->updated_at);
            }
            $before = $creating ? [] : $this->auditable($promotion);
            $promotion->fill(array_intersect_key($data, array_flip(['name', 'description', 'discount_type', 'value', 'scope', 'starts_at', 'ends_at', 'is_active', 'priority'])));
            if (array_key_exists('name', $data)) {
                $promotion->name = (string) PlainText::clean($data['name']);
            }
            if (array_key_exists('description', $data)) {
                $promotion->description = PlainText::clean($data['description']) ?: null;
            }
            $errors = [];
            if ($promotion->discount_type === PromotionDiscountType::Percent && $promotion->value > 10000) {
                $errors['value'][] = 'Percentual deve estar entre 1 e 10000 (basis points).';
            }
            if ($promotion->ends_at !== null && $promotion->ends_at <= $promotion->starts_at) {
                $errors['ends_at'][] = 'O fim deve ser posterior ao início.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $promotion->save();

            foreach (['product' => 'product_ids', 'category' => 'category_ids', 'brand' => 'brand_ids'] as $kind => $key) {
                if (array_key_exists($key, $data)) {
                    $promotion->syncTargets($kind, array_map('intval', $data[$key]));
                }
            }
            if ($promotion->scope === PromotionScope::Targeted
                && $promotion->targetIds('product') === [] && $promotion->targetIds('category') === [] && $promotion->targetIds('brand') === []) {
                $errors['product_ids'][] = 'Promoção direcionada exige ao menos um produto, categoria ou marca.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $this->audit($creating ? 'promotion.created' : 'promotion.updated', 'promotion', $promotion->id, $before, $this->auditable($promotion));

            return $promotion->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function auditable(Promotion $p): array
    {
        return [
            ...collect($p->only(self::AUDITED))->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? P::ts($v) : $v))->all(),
            'product_ids' => $p->exists ? $p->targetIds('product') : [],
            'category_ids' => $p->exists ? $p->targetIds('category') : [],
            'brand_ids' => $p->exists ? $p->targetIds('brand') : [],
        ];
    }
}
