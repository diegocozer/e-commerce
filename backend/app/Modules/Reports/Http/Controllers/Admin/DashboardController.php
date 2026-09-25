<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Admin;

use App\Modules\Reports\Http\Resources\DashboardResource;
use App\Modules\Reports\Queries\DashboardQuery;

final class DashboardController
{
    public function show(DashboardQuery $dashboard): DashboardResource
    {
        return new DashboardResource($dashboard->get());
    }
}
