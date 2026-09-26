<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Admin;

use App\Modules\Pricing\Enums\CouponType;
use App\Modules\Pricing\Exceptions\PricingConflict;
use App\Modules\Pricing\Http\Resources\PricingPresenter as P;
use App\Modules\Pricing\Models\Coupon;
use App\Modules\Pricing\Models\CouponRedemption;
use App\Modules\Pricing\Support\RecordsAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CouponController
{
    use RecordsAudit;

    private const array AUDITED = ['code', 'type', 'value', 'min_order_cents', 'max_discount_cents', 'starts_at', 'ends_at', 'usage_limit', 'usage_limit_per_customer', 'is_active'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['sometimes', 'string', 'min:1', 'max:40'],
            'status' => ['sometimes', Rule::in(['scheduled', 'active', 'expired', 'exhausted', 'inactive'])],
            'type' => ['sometimes', Rule::enum(CouponType::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $now = now();
        $query = Coupon::query()->orderByDesc('id')
            ->when(isset($f['q']), fn ($q) => $q->where('code', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtoupper($f['q'])).'%'))
            ->when(isset($f['type']), fn ($q) => $q->where('type', $f['type']));
        $active = fn ($q) => $q->where('is_active', true)->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now));
        match ($f['status'] ?? null) {
            'inactive' => $query->where('is_active', false),
            'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $now),
            'expired' => $query->where('is_active', true)->where('ends_at', '<=', $now),
            'exhausted' => $active($query)->whereNotNull('usage_limit')->whereColumn('times_used', '>=', 'usage_limit'),
            'active' => $active($query)->where(fn ($w) => $w->whereNull('usage_limit')->orWhereColumn('times_used', '<', 'usage_limit')),
            default => null,
        };
        $page = $query->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return new JsonResponse(P::paginated($page, $page->getCollection()->map(fn (Coupon $c) => P::coupon($c))->all()));
    }

    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['data' => P::coupon(Coupon::query()->findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => P::coupon($this->save(new Coupon, $request))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return new JsonResponse(['data' => P::coupon($this->save(Coupon::query()->findOrFail($id), $request))]);
    }

    public function destroy(int $id): Response
    {
        $coupon = Coupon::query()->findOrFail($id);
        $coupon->delete();
        $this->audit('coupon.deleted', 'coupon', $coupon->id, ['code' => $coupon->code], []);

        return response()->noContent();
    }

    public function redemptions(Request $request, int $id): JsonResponse
    {
        $coupon = Coupon::query()->withTrashed()->findOrFail($id);
        $f = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = CouponRedemption::query()->where('coupon_id', $coupon->id)->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 25));
        $orders = DB::table('orders')->whereIn('id', $page->getCollection()->pluck('order_id'))->get(['id', 'number', 'status'])->keyBy('id');
        $customers = DB::table('customers')->whereIn('id', $page->getCollection()->pluck('customer_id'))->pluck('name', 'id');

        return new JsonResponse(P::paginated($page, $page->getCollection()->map(fn (CouponRedemption $r) => [
            'id' => $r->id,
            'order' => ['id' => $r->order_id, 'number' => $orders[$r->order_id]->number ?? null, 'status' => $orders[$r->order_id]->status ?? null],
            'customer' => ['id' => $r->customer_id, 'name' => $customers[$r->customer_id] ?? null],
            'discount_cents' => $r->discount_cents,
            'created_at' => P::ts($r->created_at),
            'cancelled_at' => P::ts($r->cancelled_at),
        ])->all()));
    }

    public function generateCode(): JsonResponse
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Coupon::query()->withTrashed()->where('code', $code)->exists());

        return new JsonResponse(['data' => ['code' => $code]]);
    }

    private function save(Coupon $coupon, Request $request): Coupon
    {
        $creating = ! $coupon->exists;
        if (is_string($request->input('code'))) {
            $request->merge(['code' => Str::upper(trim($request->input('code')))]);
        }
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'code' => [$req, 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->whereNull('deleted_at')->ignore($coupon->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => [$req, Rule::enum(CouponType::class)],
            'value' => ['sometimes', 'integer', 'min:0'],
            'min_order_cents' => ['sometimes', 'integer', 'min:0'],
            'max_discount_cents' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'expected_updated_at' => ['sometimes', 'date'],
            'times_used' => ['prohibited'],
        ]);

        return DB::transaction(function () use ($coupon, $data, $creating) {
            if (! $creating) {
                $coupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
                PricingConflict::checkStale($data['expected_updated_at'] ?? null, $coupon->updated_at);
            }
            $before = $creating ? [] : $this->auditable($coupon);
            unset($data['expected_updated_at']);
            $coupon->fill($data);
            $type = $coupon->type;
            $coupon->value = $type === CouponType::FreeShipping ? 0 : ($data['value'] ?? $coupon->value ?? 0);
            $errors = [];
            if ($type === CouponType::Percent && ($coupon->value < 1 || $coupon->value > 10000)) {
                $errors['value'][] = 'Percentual deve estar entre 1 e 10000 (basis points).';
            }
            if ($type === CouponType::Fixed && $coupon->value < 1) {
                $errors['value'][] = 'Informe o valor do desconto em centavos.';
            }
            if ($type !== CouponType::Percent && $coupon->max_discount_cents !== null) {
                $errors['max_discount_cents'][] = 'Teto de desconto apenas para cupons percentuais.';
            }
            if ($coupon->starts_at !== null && $coupon->ends_at !== null && $coupon->ends_at <= $coupon->starts_at) {
                $errors['ends_at'][] = 'O fim deve ser posterior ao início.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $coupon->min_order_cents ??= 0;
            $coupon->save();
            $this->audit($creating ? 'coupon.created' : 'coupon.updated', 'coupon', $coupon->id, $before, $this->auditable($coupon));

            return $coupon->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function auditable(Coupon $c): array
    {
        return collect($c->only(self::AUDITED))->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? P::ts($v) : $v))->all();
    }
}
