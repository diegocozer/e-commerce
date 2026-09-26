<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Admin;

use App\Modules\Pricing\Enums\PriceListKind;
use App\Modules\Pricing\Exceptions\PricingConflict;
use App\Modules\Pricing\Http\Resources\PricingPresenter as P;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceTier;
use App\Modules\Pricing\Support\RecordsAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PriceListController
{
    use RecordsAudit;

    private const array AUDITED = ['code', 'name', 'kind', 'discount_bp', 'is_default', 'is_active'];

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => PriceList::query()->orderBy('name')->get()->map(fn (PriceList $l) => P::priceList($l))->values()]);
    }

    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['data' => P::priceList(PriceList::query()->findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(null));
        $list = DB::transaction(function () use ($data) {
            if (! empty($data['is_default'])) {
                PriceList::query()->where('is_default', true)->update(['is_default' => false]);
            }
            $list = PriceList::query()->create([...$data, 'kind' => $data['kind'] ?? PriceListKind::Custom->value]);
            $this->audit('price_list.created', 'price_list', $list->id, [], $list->only(self::AUDITED));

            return $list;
        });

        return new JsonResponse(['data' => P::priceList($list->fresh())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->rules($id));
        $list = DB::transaction(function () use ($data, $id) {
            $list = PriceList::query()->lockForUpdate()->findOrFail($id);
            PricingConflict::checkStale($data['expected_updated_at'] ?? null, $list->updated_at);
            unset($data['expected_updated_at']);
            $before = $list->only(self::AUDITED);
            if (! empty($data['is_default']) && ! $list->is_default) {
                PriceList::query()->where('is_default', true)->update(['is_default' => false]);
            }
            $list->fill($data)->save();
            $this->audit('price_list.updated', 'price_list', $list->id, $before, $list->only(self::AUDITED));

            return $list;
        });

        return new JsonResponse(['data' => P::priceList($list->fresh())]);
    }

    public function destroy(int $id): Response
    {
        DB::transaction(function () use ($id): void {
            $list = PriceList::query()->lockForUpdate()->findOrFail($id);
            $blockers = [];
            if ($list->is_default) {
                $blockers[] = ['type' => 'price_list', 'id' => $list->id, 'label' => 'Tabela padrão'];
            }
            foreach (DB::table('customers')->where('price_list_id', $list->id)->limit(20)->get(['id', 'name']) as $c) {
                $blockers[] = ['type' => 'customer', 'id' => (int) $c->id, 'label' => $c->name];
            }
            foreach (DB::table('companies')->where('price_list_id', $list->id)->limit(20)->get(['id', 'legal_name']) as $c) {
                $blockers[] = ['type' => 'company', 'id' => (int) $c->id, 'label' => $c->legal_name];
            }
            if ($blockers !== []) {
                throw PricingConflict::inUse('Tabela de preço em uso.', $blockers);
            }
            $list->delete();
            $this->audit('price_list.deleted', 'price_list', $list->id, $list->only(self::AUDITED), []);
        });

        return response()->noContent();
    }

    public function tiers(Request $request, int $id): JsonResponse
    {
        $list = PriceList::query()->findOrFail($id);
        $f = $request->validate(['q' => ['sometimes', 'string', 'min:1', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = PriceTier::query()->where('price_list_id', $list->id)->orderBy('variant_id')->orderBy('min_quantity');
        if (isset($f['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']).'%';
            $query->whereIn('variant_id', DB::table('product_variants')->where(fn ($w) => $w->where('sku', 'ilike', $like)->orWhere('name', 'ilike', $like))->select('id'));
        }
        $page = $query->paginate((int) ($f['per_page'] ?? 25))->withQueryString();
        $variants = P::variants($page->getCollection()->pluck('variant_id')->unique()->values()->all());

        return new JsonResponse(P::paginated($page, $page->getCollection()->map(fn (PriceTier $t) => [...P::tier($t), 'variant' => $variants[$t->variant_id] ?? null])->all()));
    }

    /** @return array<string, mixed> */
    private function rules(?int $id): array
    {
        $req = $id === null ? 'required' : 'sometimes';

        return [
            // code is immutable after creation
            'code' => $id === null ? ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('price_lists', 'code')] : ['prohibited'],
            'name' => [$req, 'string', 'max:120'],
            'kind' => ['sometimes', Rule::enum(PriceListKind::class)],
            'discount_bp' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'expected_updated_at' => ['sometimes', 'date'],
        ];
    }
}
