<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Admin;

use App\Modules\Customers\Actions\AdminCustomerActions;
use App\Modules\Customers\Http\Requests\Admin\CustomerIndexRequest;
use App\Modules\Customers\Http\Requests\Admin\UpdateCustomerRequest;
use App\Modules\Customers\Http\Resources\AdminCustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\Documents\Cnpj;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class CustomerController
{
    public function index(CustomerIndexRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();
        $query = Customer::query()->with('company');

        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }
        if (isset($f['q'])) {
            $q = trim((string) $f['q']);
            $like = '%'.addcslashes($q, '%_\\').'%';
            $digits = (string) preg_replace('/\D/', '', $q);
            $doc = Cnpj::normalize($q);
            $query->where(function ($w) use ($like, $digits, $doc): void {
                $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like);
                if (strlen($digits) === 11) {
                    $w->orWhere('cpf', $digits);
                }
                if (strlen($doc) === 14) {
                    $w->orWhereHas('company', fn ($c) => $c->where('cnpj', $doc));
                }
            });
        }
        foreach (['type', 'price_list_id', 'company_id'] as $field) {
            if (isset($f[$field])) {
                $query->where($field, $f[$field]);
            }
        }
        if (isset($f['is_active'])) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if (isset($f['date_from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($f['date_from'], 'America/Sao_Paulo')->startOfDay()->utc());
        }
        if (isset($f['date_to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($f['date_to'], 'America/Sao_Paulo')->addDay()->startOfDay()->utc());
        }

        // Stats sorts read the orders table (read-only; Customers cannot import Orders).
        match ($f['sort'] ?? '-created_at') {
            'name' => $query->orderBy('name'),
            'created_at' => $query->orderBy('created_at'),
            '-total_spent' => $query->orderByDesc(DB::raw("(select coalesce(sum(o.total_cents), 0) from orders o where o.customer_id = customers.id and o.payment_status in ('approved'))")),
            '-last_order_at' => $query->orderByRaw('(select max(o.created_at) from orders o where o.customer_id = customers.id) desc nulls last'),
            default => $query->orderByDesc('created_at'),
        };
        $query->orderByDesc('id');

        $page = $query->paginate((int) ($f['per_page'] ?? 25))->withQueryString();
        AdminCustomerResource::preloadStats($page->items());

        return AdminCustomerResource::collection($page);
    }

    public function show(int $customer): AdminCustomerResource
    {
        $model = Customer::withTrashed()->with('company')->findOrFail($customer);
        AdminCustomerResource::$stats = [];
        $resource = new AdminCustomerResource($model);
        $resource->withAddresses = true;

        return $resource;
    }

    public function update(UpdateCustomerRequest $request, int $customer, AdminCustomerActions $actions): AdminCustomerResource
    {
        $model = Customer::query()->with('company')->findOrFail($customer);
        $actions->update(self::actor($request), $model, $request->validated());

        return $this->show($customer);
    }

    public static function actor(Request $request): ActorRef
    {
        return ActorRef::admin((int) $request->user('admin')->getAuthIdentifier());
    }
}
