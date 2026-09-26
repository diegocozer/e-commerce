<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Support\ImageUrls;
use Illuminate\Http\JsonResponse;

final class BrandController
{
    public function index(): JsonResponse
    {
        $disk = (string) config('catalog.images_disk');
        $brands = Brand::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'logo_url' => ImageUrls::url($disk, $b->logo_path)]);

        return new JsonResponse(['data' => $brands->values()]);
    }
}
