<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Admin;

use App\Modules\Pricing\Http\Resources\PricingPresenter as P;
use App\Modules\Pricing\Models\CustomerPrice;
use App\Modules\Pricing\Support\RecordsAudit;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomerPriceController
{
    use RecordsAudit;

    private const array AUDITED = ['customer_id', 'company_id', 'variant_id', 'price_cents', 'starts_at', 'ends_at'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'customer_id' => ['sometimes', 'integer'], 'company_id' => ['sometimes', 'integer'], 'variant_id' => ['sometimes', 'integer'],
            'active_at' => ['sometimes', 'date'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = CustomerPrice::query()->orderByDesc('id');
        foreach (['customer_id', 'company_id', 'variant_id'] as $k) {
            $query->when(isset($f[$k]), fn ($q) => $q->where($k, $f[$k]));
        }
        if (isset($f['active_at'])) {
            $at = \Carbon\CarbonImmutable::parse($f['active_at']);
            $query->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
        }
        $page = $query->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return new JsonResponse(P::paginated($page, $page->getCollection()->map(fn (CustomerPrice $cp) => P::customerPrice($cp))->all()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'required_without:company_id', 'prohibits:company_id', Rule::exists('customers', 'id')],
            'company_id' => ['nullable', 'integer', 'required_without:customer_id', Rule::exists('companies', 'id')],
            'variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'price_cents' => ['required', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
        ]);
        $cp = $this->persist(new CustomerPrice, $data, (int) $request->user('admin')->getAuthIdentifier());

        return new JsonResponse(['data' => P::customerPrice($cp)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $cp = CustomerPrice::query()->findOrFail($id);
        $data = $request->validate([
            'price_cents' => ['sometimes', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'customer_id' => ['prohibited'], 'company_id' => ['prohibited'], 'variant_id' => ['prohibited'],
        ]);
        $starts = array_key_exists('starts_at', $data) ? $data['starts_at'] : $cp->starts_at;
        $ends = array_key_exists('ends_at', $data) ? $data['ends_at'] : $cp->ends_at;
        if ($starts !== null && $ends !== null && \Carbon\CarbonImmutable::parse($ends) <= \Carbon\CarbonImmutable::parse($starts)) {
            throw ValidationException::withMessages(['ends_at' => ['O fim da vigência deve ser posterior ao início.']]);
        }

        return new JsonResponse(['data' => P::customerPrice($this->persist($cp, $data, null))]);
    }

    public function destroy(int $id): Response
    {
        $cp = CustomerPrice::query()->findOrFail($id);
        $cp->delete();
        $this->audit('customer_price.deleted', 'price_list', null, $cp->only(self::AUDITED), []);

        return response()->noContent();
    }

    /** @param  array<string, mixed>  $data */
    private function persist(CustomerPrice $cp, array $data, ?int $createdBy): CustomerPrice
    {
        $creating = ! $cp->exists;
        $before = $creating ? [] : $cp->only(self::AUDITED);
        try {
            DB::transaction(function () use ($cp, $data, $createdBy): void {
                $cp->fill($data);
                if ($createdBy !== null) {
                    $cp->forceFill(['created_by' => $createdBy]);
                }
                $cp->save();
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23P01') { // EXCLUDE: overlapping validity
                throw ValidationException::withMessages(['starts_at' => ['Já existe preço vigente neste período.']]);
            }
            throw $e;
        }
        $this->audit($creating ? 'customer_price.created' : 'customer_price.updated', 'product_variant', $cp->variant_id, $before, $cp->only(self::AUDITED));

        return $cp->fresh();
    }
}
