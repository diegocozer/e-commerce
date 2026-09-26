<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Admin;

use App\Modules\Settings\Actions\UpdateSettings;
use App\Modules\Settings\Http\Requests\UpdateSettingsRequest;
use App\Modules\Settings\Http\Resources\SettingResource;
use App\Shared\Domain\ActorRef;
use Illuminate\Http\JsonResponse;

final class SettingController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => SettingResource::all()]);
    }

    public function update(UpdateSettingsRequest $request, UpdateSettings $update): JsonResponse
    {
        $expected = $request->validated('expected_updated_at');
        $update->handle($request->settingValues(), ActorRef::admin((int) $request->user('admin')->getAuthIdentifier()), is_string($expected) ? $expected : null);

        return new JsonResponse(['data' => SettingResource::all()]);
    }
}
