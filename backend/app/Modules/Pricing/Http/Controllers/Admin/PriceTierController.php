<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Admin;

use App\Modules\Pricing\Http\Resources\PricingPresenter as P;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceTier;
use App\Modules\Pricing\Support\RecordsAudit;
use App\Shared\Domain\Quantity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** GET/PUT /admin/variants/{variantId}/price-tiers (API.md §3.G.6). */
final class PriceTierController
{
    use RecordsAudit;

    public function show(int $variantId): JsonResponse
    {
        abort_unless(DB::table('product_variants')->where('id', $variantId)->exists(), 404);

        return new JsonResponse(['data' => $this->payload($variantId)]);
    }

    public function update(Request $request, int $variantId): JsonResponse
    {
        abort_unless(DB::table('product_variants')->where('id', $variantId)->whereNull('deleted_at')->exists(), 404);
        $tiers = $request->input('tiers');
        if (is_array($tiers)) {
            foreach ($tiers as $i => $t) {
                if (is_array($t) && (is_int($t['min_quantity'] ?? null) || is_float($t['min_quantity'] ?? null))) {
                    $tiers[$i]['min_quantity'] = var_export($t['min_quantity'], true);
                    $tiers[$i]['min_quantity'] = str_ends_with($tiers[$i]['min_quantity'], '.0') ? substr($tiers[$i]['min_quantity'], 0, -2) : $tiers[$i]['min_quantity'];
                }
            }
            $request->merge(['tiers' => $tiers]);
        }
        $data = $request->validate([
            'price_list_id' => ['present', 'nullable', 'integer', Rule::exists('price_lists', 'id')],
            'tiers' => ['present', 'array', 'max:20'],
            'tiers.*.min_quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/', 'distinct'],
            'tiers.*.price_cents' => ['required', 'integer', 'min:1'],
        ]);
        $admin = $request->user('admin');
        $permission = $data['price_list_id'] === null ? 'prices.manage' : 'pricing.manage';
        if (! $admin->can($permission)) {
            throw new AuthorizationException;
        }

        $rows = collect($data['tiers'])->map(fn ($t) => ['min' => Quantity::fromString($t['min_quantity']), 'price' => (int) $t['price_cents']])
            ->sortBy(fn ($t) => $t['min']->milli())->values();
        $errors = [];
        $previous = null;
        foreach ($rows as $row) {
            if (! $row['min']->isPositive()) {
                $errors['tiers.'.array_search($row, $rows->all(), true).'.min_quantity'] = ['A quantidade mínima deve ser maior que zero.'];
            }
            if ($previous !== null && $row['price'] > $previous) {
                $index = collect($data['tiers'])->search(fn ($t) => Quantity::fromString($t['min_quantity'])->equals($row['min']));
                $errors["tiers.{$index}.price_cents"] = ['Os preços devem ser não crescentes conforme a quantidade aumenta.'];
            }
            $previous = $row['price'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($variantId, $data, $rows): void {
            $query = PriceTier::query()->where('variant_id', $variantId)->where('price_list_id', $data['price_list_id']);
            $before = $query->clone()->orderBy('min_quantity')->get()->map(fn (PriceTier $t) => [$t->min_quantity->toDecimalString(), $t->price_cents])->all();
            $query->delete();
            foreach ($rows as $row) {
                PriceTier::query()->create(['variant_id' => $variantId, 'price_list_id' => $data['price_list_id'], 'min_quantity' => $row['min'], 'price_cents' => $row['price']]);
            }
            $this->audit('price_tiers.replaced', 'product_variant', $variantId, ['price_list_id' => $data['price_list_id'], 'tiers' => $before],
                ['price_list_id' => $data['price_list_id'], 'tiers' => $rows->map(fn ($r) => [$r['min']->toDecimalString(), $r['price']])->all()]);
        });

        return new JsonResponse(['data' => $this->payload($variantId)]);
    }

    /** @return array<string, mixed> */
    private function payload(int $variantId): array
    {
        $tiers = PriceTier::query()->where('variant_id', $variantId)->orderBy('min_quantity')->get();
        $lists = PriceList::query()->whereIn('id', $tiers->pluck('price_list_id')->filter()->unique())->orderBy('name')->get();

        return [
            'base' => $tiers->whereNull('price_list_id')->map(fn (PriceTier $t) => P::tier($t))->values()->all(),
            'price_lists' => $lists->map(fn (PriceList $l) => [
                'price_list' => P::priceList($l),
                'tiers' => $tiers->where('price_list_id', $l->id)->map(fn (PriceTier $t) => P::tier($t))->values()->all(),
            ])->values()->all(),
        ];
    }
}
