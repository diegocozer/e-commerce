<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** `{ data: Dashboard }` — blocks the admin may not see are null (API.md §3.G.2). */
final class DashboardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $d */
        $d = $this->resource;
        $user = $request->user('admin');
        $can = static fn (string ...$permissions): bool => $user !== null && $user->canAny($permissions);

        return [
            'generated_at' => $d['generated_at'],
            'sales' => $can('reports.view', 'reports.sales') ? $d['sales'] : null,
            'queues' => $can('orders.view') ? $d['queues'] : null,
            'todays_deliveries' => $can('orders.view') ? $d['todays_deliveries'] : null,
            'low_stock' => $can('inventory.view') ? $d['low_stock'] : null,
        ];
    }
}
