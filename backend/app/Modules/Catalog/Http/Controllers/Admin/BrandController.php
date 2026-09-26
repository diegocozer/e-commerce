<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Exceptions\StaleResource;
use App\Modules\Catalog\Http\Requests\Admin\BrandRequest;
use App\Modules\Catalog\Http\Resources\Admin\AdminCatalogPresenter as P;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Support\RecordsAudit;
use App\Modules\Catalog\Support\SlugRules;
use App\Modules\Catalog\Support\StoresUploadedImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class BrandController
{
    use RecordsAudit;

    private const array AUDITED = ['name', 'slug', 'is_active', 'logo_path'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'include_deleted' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['name', '-name', 'created_at', '-created_at'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Brand::query()
            ->withCount(['products as products_count' => fn ($q) => $q->whereNull('deleted_at')])
            ->when($request->boolean('include_deleted'), fn ($q) => $q->withTrashed())
            ->when(isset($f['q']), fn ($q) => $q->whereRaw('public.f_unaccent(name) ILIKE public.f_unaccent(?)', ['%'.$f['q'].'%']))
            ->when(isset($f['is_active']), fn ($q) => $q->where('is_active', (bool) $f['is_active']));
        $sort = $f['sort'] ?? 'name';
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');
        $page = $query->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return new JsonResponse(P::paginated($page, $page->getCollection()->map(fn (Brand $b) => P::brand($b, (int) $b->products_count))->all()));
    }

    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['data' => P::brand(Brand::query()->findOrFail($id))]);
    }

    public function store(BrandRequest $request): JsonResponse
    {
        $data = $request->validated();
        $brand = DB::transaction(function () use ($data) {
            $brand = new Brand(['name' => $data['name'], 'is_active' => $data['is_active'] ?? true]);
            $brand->slug = $data['slug'] ?? SlugRules::generate('brands', $data['name']);
            $brand->save();
            $this->audit('brand.created', 'brand', $brand->id, [], $brand->only(self::AUDITED));

            return $brand;
        });

        return new JsonResponse(['data' => P::brand($brand->fresh())], 201);
    }

    public function update(BrandRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $brand = DB::transaction(function () use ($data, $id) {
            $brand = Brand::query()->lockForUpdate()->findOrFail($id);
            StaleResource::check($data['expected_updated_at'] ?? null, $brand->updated_at);
            $before = $brand->only(self::AUDITED);
            $brand->fill(array_intersect_key($data, array_flip(['name', 'is_active'])));
            if (! empty($data['slug'])) {
                $brand->slug = $data['slug'];
            }
            $brand->save();
            $this->audit('brand.updated', 'brand', $brand->id, $before, $brand->only(self::AUDITED));

            return $brand;
        });

        return new JsonResponse(['data' => P::brand($brand->fresh())]);
    }

    public function destroy(int $id): Response
    {
        $brand = Brand::query()->findOrFail($id);
        $brand->delete(); // RN-CAT-009: products keep the brand
        $this->audit('brand.deleted', 'brand', $brand->id, $brand->only(self::AUDITED), []);

        return response()->noContent();
    }

    public function storeLogo(Request $request, int $id): JsonResponse
    {
        $request->validate(['file' => StoresUploadedImage::RULES]);
        $brand = Brand::query()->findOrFail($id);
        $old = $brand->logo_path;
        $brand->logo_path = StoresUploadedImage::store($request->file('file'), 'brands/'.$brand->id);
        $brand->save();
        if ($old !== null) {
            Storage::disk(P::disk())->delete($old);
        }
        $this->audit('brand.logo_updated', 'brand', $brand->id, ['logo_path' => $old], ['logo_path' => $brand->logo_path]);

        return new JsonResponse(['data' => P::brand($brand)]);
    }

    public function destroyLogo(int $id): Response
    {
        $brand = Brand::query()->findOrFail($id);
        if ($brand->logo_path !== null) {
            Storage::disk(P::disk())->delete($brand->logo_path);
            $this->audit('brand.logo_deleted', 'brand', $brand->id, ['logo_path' => $brand->logo_path], ['logo_path' => null]);
            $brand->logo_path = null;
            $brand->save();
        }

        return response()->noContent();
    }
}
