<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Http\Resources\AdminMeResource;
use Illuminate\Http\Request;

final class MeController
{
    public function show(Request $request): AdminMeResource
    {
        return new AdminMeResource($request->user('admin'));
    }
}
