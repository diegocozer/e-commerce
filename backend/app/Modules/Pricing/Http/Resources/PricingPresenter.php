<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Resources;

use App\Modules\Pricing\Enums\CouponType;
use App\Modules\Pricing\Models\Coupon;
use App\Modules\Pricing\Models\CustomerPrice;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceTier;
use App\Modules\Pricing\Models\Promotion;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Admin JSON shapes of API.md §2.13 (pricing part). Catalog names are read
 * with plain queries (Pricing does not depend on Catalog, ARCHITECTURE §2.5).
 */
final class PricingPresenter
{
    public static function ts(?DateTimeInterface $d): ?string
    {
        return $d === null ? null : CarbonImmutable::instance($d)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** @return array<string, mixed> */
    public static function tier(PriceTier $t): array
    {
        return [
            'id' => $t->id, 'variant_id' => $t->variant_id, 'price_list_id' => $t->price_list_id,
            'min_quantity' => $t->min_quantity->toNumber(), 'price_cents' => $t->price_cents,
        ];
    }

    /** @return array<string, mixed> */
    public static function priceList(PriceList $l): array
    {
        return [
            'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'kind' => $l->kind->value, 'discount_bp' => $l->discount_bp,
            'is_default' => $l->is_default, 'is_active' => $l->is_active,
            'customers_count' => DB::table('customers')->where('price_list_id', $l->id)->count(),
            'companies_count' => DB::table('companies')->where('price_list_id', $l->id)->count(),
            'tiers_count' => DB::table('price_tiers')->where('price_list_id', $l->id)->count(),
            'created_at' => self::ts($l->created_at), 'updated_at' => self::ts($l->updated_at),
        ];
    }

    /**
     * AdminVariantPickerItem by id.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public static function variants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->whereIn('v.id', $ids)
            ->get(['v.id', 'v.sku', 'v.name', 'v.price_cents', 'v.is_active', 'p.id as product_id', 'p.name as product_name', 'p.sale_unit']) as $r) {
            $out[(int) $r->id] = [
                'id' => (int) $r->id, 'sku' => $r->sku, 'name' => $r->name, 'sale_unit' => $r->sale_unit,
                'price_cents' => (int) $r->price_cents, 'is_active' => (bool) $r->is_active,
                'product' => ['id' => (int) $r->product_id, 'name' => $r->product_name],
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function customerPrice(CustomerPrice $cp): array
    {
        $customer = $cp->customer_id !== null ? DB::table('customers')->where('id', $cp->customer_id)->first(['id', 'name', 'email']) : null;
        $company = $cp->company_id !== null ? DB::table('companies')->where('id', $cp->company_id)->first(['id', 'legal_name']) : null;
        $creator = $cp->getAttribute('created_by') !== null ? DB::table('admin_users')->where('id', $cp->getAttribute('created_by'))->first(['id', 'name']) : null;

        return [
            'id' => $cp->id,
            'customer' => $customer !== null ? ['id' => (int) $customer->id, 'name' => $customer->name, 'email' => $customer->email] : null,
            'company' => $company !== null ? ['id' => (int) $company->id, 'legal_name' => $company->legal_name] : null,
            'variant' => self::variants([$cp->variant_id])[$cp->variant_id] ?? null,
            'price_cents' => $cp->price_cents,
            'starts_at' => self::ts($cp->starts_at),
            'ends_at' => self::ts($cp->ends_at),
            'created_by' => $creator !== null ? ['id' => (int) $creator->id, 'name' => $creator->name] : null,
            'created_at' => self::ts($cp->created_at),
            'updated_at' => self::ts($cp->updated_at),
        ];
    }

    public static function promotionStatus(Promotion $p): string
    {
        $now = now();

        return match (true) {
            ! $p->is_active => 'inactive',
            $p->starts_at > $now => 'scheduled',
            $p->ends_at !== null && $p->ends_at <= $now => 'ended',
            default => 'active',
        };
    }

    /** @return array<string, mixed> */
    public static function promotion(Promotion $p): array
    {
        $products = $p->targetIds('product');
        $categories = $p->targetIds('category');
        $brands = $p->targetIds('brand');

        return [
            'id' => $p->id, 'name' => $p->name, 'description' => $p->description,
            'discount_type' => $p->discount_type->value, 'value' => $p->value, 'scope' => $p->scope->value,
            'starts_at' => self::ts($p->starts_at), 'ends_at' => self::ts($p->ends_at),
            'is_active' => $p->is_active, 'priority' => $p->priority, 'status' => self::promotionStatus($p),
            'product_ids' => $products, 'category_ids' => $categories, 'brand_ids' => $brands,
            'targets' => [
                'products' => DB::table('products')->whereIn('id', $products ?: [0])->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])->all(),
                'categories' => DB::table('categories')->whereIn('id', $categories ?: [0])->orderBy('name')->get(['id', 'name', 'slug'])
                    ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug, 'url_path' => '/'.$r->slug])->all(),
                'brands' => DB::table('brands')->whereIn('id', $brands ?: [0])->orderBy('name')->get(['id', 'name', 'slug'])
                    ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug])->all(),
            ],
            'created_at' => self::ts($p->created_at), 'updated_at' => self::ts($p->updated_at), 'deleted_at' => self::ts($p->deleted_at),
        ];
    }

    public static function couponStatus(Coupon $c): string
    {
        $now = now();

        return match (true) {
            ! $c->is_active => 'inactive',
            $c->starts_at !== null && $c->starts_at > $now => 'scheduled',
            $c->ends_at !== null && $c->ends_at <= $now => 'expired',
            $c->usage_limit !== null && $c->times_used >= $c->usage_limit => 'exhausted',
            default => 'active',
        };
    }

    /** @return array<string, mixed> */
    public static function coupon(Coupon $c): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'description' => $c->description, 'type' => $c->type->value,
            'value' => $c->type === CouponType::FreeShipping ? 0 : $c->value,
            'min_order_cents' => $c->min_order_cents, 'max_discount_cents' => $c->max_discount_cents,
            'starts_at' => self::ts($c->starts_at), 'ends_at' => self::ts($c->ends_at),
            'usage_limit' => $c->usage_limit, 'usage_limit_per_customer' => $c->usage_limit_per_customer,
            'times_used' => $c->times_used, 'is_active' => $c->is_active, 'status' => self::couponStatus($c),
            'created_at' => self::ts($c->created_at), 'updated_at' => self::ts($c->updated_at), 'deleted_at' => self::ts($c->deleted_at),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  list<mixed>  $data
     * @return array<string, mixed>
     */
    public static function paginated($paginator, array $data): array
    {
        $b = $paginator->toArray();

        return [
            'data' => $data,
            'links' => ['first' => $b['first_page_url'], 'last' => $b['last_page_url'], 'prev' => $b['prev_page_url'], 'next' => $b['next_page_url']],
            'meta' => [
                'current_page' => $b['current_page'], 'from' => $b['from'], 'last_page' => $b['last_page'], 'path' => $b['path'],
                'per_page' => $b['per_page'], 'to' => $b['to'], 'total' => $b['total'], 'links' => $b['links'],
            ],
        ];
    }
}
