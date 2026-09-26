<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\Company;
use App\Modules\Customers\Support\Iso;
use App\Modules\Customers\Support\PriceListLookup;
use App\Shared\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API.md §2.14 AdminCompany (CNPJ masked). @mixin Company */
final class AdminCompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Company $c */
        $c = $this->resource;
        $c->loadMissing('customers:id,company_id,name,email');

        return [
            'id' => $c->id,
            'legal_name' => $c->legal_name,
            'trade_name' => $c->trade_name,
            'cnpj_masked' => Mask::cnpj($c->cnpj),
            'state_registration' => $c->state_registration,
            'state_registration_exempt' => $c->state_registration_exempt,
            'price_list' => app(PriceListLookup::class)->find($c->price_list_id),
            'customers' => $c->customers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values()->all(),
            'created_at' => Iso::dt($c->created_at),
            'updated_at' => Iso::dt($c->updated_at),
        ];
    }
}
