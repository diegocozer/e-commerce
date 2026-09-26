<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Contracts\CustomerStatsProvider;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerProfile;
use App\Modules\Customers\Support\Iso;
use App\Modules\Customers\Support\PriceListLookup;
use App\Shared\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API.md §2.14 AdminCustomer (documents masked; `addresses` only on the detail).
 * Stats come from CustomerStatsProvider (pre-loaded for a page via withStats()).
 *
 * @mixin Customer
 */
final class AdminCustomerResource extends JsonResource
{
    /** @var array<int, array<string, mixed>> */
    public static array $stats = [];

    public bool $withAddresses = false;

    /** @param  iterable<Customer>  $customers */
    public static function preloadStats(iterable $customers): void
    {
        $ids = [];
        foreach ($customers as $c) {
            $ids[] = $c->id;
        }
        self::$stats = $ids === [] ? [] : app(CustomerStatsProvider::class)->statsFor($ids);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Customer $c */
        $c = $this->resource;
        $c->loadMissing('company');
        $lists = app(PriceListLookup::class);
        $stats = self::$stats[$c->id] ?? app(CustomerStatsProvider::class)->statsFor([$c->id])[$c->id] ?? [];
        $effective = CustomerProfile::assignedPriceListId($c) ?? $lists->defaultId();

        $data = [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'type' => $c->type->value,
            'name' => $c->name,
            'email' => $c->email,
            'email_verified_at' => Iso::dt($c->email_verified_at),
            'phone' => $c->phone,
            'cpf_masked' => $c->cpf === null ? null : Mask::cpf($c->cpf),
            'company' => $c->company === null ? null : (new AdminCompanyResource($c->company))->resolve($request),
            'price_list' => $lists->find($c->price_list_id),
            'effective_price_list' => $lists->find($effective),
            'is_active' => $c->is_active,
            'marketing_opt_in' => (bool) $c->marketing_opt_in,
            'terms_version' => $c->terms_version,
            'terms_accepted_at' => Iso::dt($c->terms_accepted_at),
            'last_login_at' => Iso::dt($c->last_login_at),
            'anonymized_at' => Iso::dt($c->anonymized_at),
            'stats' => [
                'orders_count' => (int) ($stats['orders_count'] ?? 0),
                'paid_orders_count' => (int) ($stats['paid_orders_count'] ?? 0),
                'total_spent_cents' => (int) ($stats['paid_total_cents'] ?? 0),
                'last_order_at' => $stats['last_order_at'] ?? null,
            ],
            'created_at' => Iso::dt($c->created_at),
            'updated_at' => Iso::dt($c->updated_at),
            'deleted_at' => Iso::dt($c->deleted_at),
        ];

        if ($this->withAddresses) {
            $data['addresses'] = AddressResource::collection(
                $c->addresses()->orderByDesc('is_default')->orderByDesc('created_at')->get(),
            )->resolve($request);
        }

        return $data;
    }
}
