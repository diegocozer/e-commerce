<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Admin;

use App\Modules\Customers\Actions\AdminCustomerActions;
use App\Modules\Customers\Http\Requests\Admin\CompanyIndexRequest;
use App\Modules\Customers\Http\Requests\Admin\UpdateCompanyRequest;
use App\Modules\Customers\Http\Resources\AdminCompanyResource;
use App\Modules\Customers\Models\Company;
use App\Shared\Domain\Documents\Cnpj;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CompanyController
{
    public function index(CompanyIndexRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();
        $query = Company::query()->with('customers:id,company_id,name,email');

        if (isset($f['q'])) {
            $like = '%'.addcslashes(trim((string) $f['q']), '%_\\').'%';
            $doc = Cnpj::normalize((string) $f['q']);
            $query->where(fn ($w) => $w->where('legal_name', 'ilike', $like)->orWhere('trade_name', 'ilike', $like)
                ->when(strlen($doc) === 14, fn ($x) => $x->orWhere('cnpj', $doc)));
        }
        if (isset($f['price_list_id'])) {
            $query->where('price_list_id', $f['price_list_id']);
        }
        match ($f['sort'] ?? 'legal_name') {
            '-created_at' => $query->orderByDesc('created_at'),
            'created_at' => $query->orderBy('created_at'),
            default => $query->orderBy('legal_name'),
        };
        $query->orderBy('id');

        return AdminCompanyResource::collection($query->paginate((int) ($f['per_page'] ?? 25))->withQueryString());
    }

    public function show(int $company): AdminCompanyResource
    {
        return new AdminCompanyResource(Company::query()->findOrFail($company));
    }

    public function update(UpdateCompanyRequest $request, int $company, AdminCustomerActions $actions): AdminCompanyResource
    {
        $model = Company::query()->findOrFail($company);

        return new AdminCompanyResource($actions->updateCompany(CustomerController::actor($request), $model, $request->validated())->refresh());
    }
}
