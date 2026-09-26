<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Events\CategoryTreeChanged;
use App\Modules\Catalog\Exceptions\ResourceInUse;
use App\Modules\Catalog\Exceptions\StaleResource;
use App\Modules\Catalog\Http\Controllers\Store\CategoryController as StoreCategoryController;
use App\Modules\Catalog\Http\Requests\Admin\CategoryRequest;
use App\Modules\Catalog\Http\Resources\Admin\AdminCatalogPresenter as P;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Support\CategoryTree;
use App\Modules\Catalog\Support\RecordsAudit;
use App\Modules\Catalog\Support\SlugRules;
use App\Modules\Catalog\Support\StoresUploadedImage;
use App\Shared\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CategoryController
{
    use RecordsAudit;

    private const array AUDITED = ['name', 'slug', 'parent_id', 'meta_title', 'meta_description', 'position', 'is_active', 'image_path'];

    public function index(Request $request): JsonResponse
    {
        $request->validate(['include_deleted' => ['sometimes', 'boolean']]);
        $query = Category::query();
        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        return new JsonResponse(['data' => P::categoryTree($query->get())]);
    }

    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['data' => P::category(Category::query()->findOrFail($id))]);
    }

    public function store(CategoryRequest $request, HtmlSanitizer $sanitizer): JsonResponse
    {
        $data = $request->validated();
        $category = DB::transaction(function () use ($data, $sanitizer) {
            $this->checkParent(null, $data['parent_id'] ?? null);
            $category = new Category;
            $this->fill($category, $data, $sanitizer);
            $category->slug = $data['slug'] ?? SlugRules::generate('categories', $data['name']);
            $category->save();
            $this->audit('category.created', 'category', $category->id, [], $category->only(self::AUDITED));

            return $category;
        });
        $this->changed($category->id);

        return new JsonResponse(['data' => P::category($category->fresh())], 201);
    }

    public function update(CategoryRequest $request, HtmlSanitizer $sanitizer, int $id): JsonResponse
    {
        $data = $request->validated();
        $category = DB::transaction(function () use ($data, $sanitizer, $id) {
            $category = Category::query()->lockForUpdate()->findOrFail($id);
            StaleResource::check($data['expected_updated_at'] ?? null, $category->updated_at);
            $before = $category->only(self::AUDITED);
            if (array_key_exists('parent_id', $data)) {
                $this->checkParent($category, $data['parent_id']);
            }
            if (($data['is_active'] ?? true) === false && $category->is_active) {
                $this->ensureNotBlocked($category, 'desativar');
            }
            $this->fill($category, $data, $sanitizer);
            if (array_key_exists('slug', $data) && $data['slug'] !== null) {
                $category->slug = $data['slug'];
            }
            $category->save();
            $this->audit('category.updated', 'category', $category->id, $before, $category->only(self::AUDITED));

            return $category;
        });
        $this->changed($category->id);

        return new JsonResponse(['data' => P::category($category->fresh())]);
    }

    public function destroy(int $id): Response
    {
        DB::transaction(function () use ($id): void {
            $category = Category::query()->lockForUpdate()->findOrFail($id);
            $this->ensureNotBlocked($category, 'excluir');
            $category->delete();
            $this->audit('category.deleted', 'category', $category->id, $category->only(self::AUDITED), []);
        });
        $this->changed($id);

        return response()->noContent();
    }

    public function reorder(Request $request): Response
    {
        $data = $request->validate([
            'parent_id' => ['present', 'nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $siblings = Category::query()->where('parent_id', $data['parent_id'])->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $ids = array_map('intval', $data['ids']);
        $sorted = $ids;
        sort($sorted);
        if ($sorted !== $siblings) {
            throw ValidationException::withMessages(['ids' => ['Envie todas as categorias irmãs, na nova ordem.']]);
        }
        DB::transaction(function () use ($ids): void {
            foreach ($ids as $position => $id) {
                Category::query()->whereKey($id)->update(['position' => $position]);
            }
            $this->audit('category.reordered', 'category', null, [], ['ids' => $ids]);
        });
        $this->changed(null);

        return response()->noContent();
    }

    public function storeImage(Request $request, int $id): JsonResponse
    {
        $request->validate(['file' => StoresUploadedImage::RULES]);
        $category = Category::query()->findOrFail($id);
        $old = $category->image_path;
        $category->image_path = StoresUploadedImage::store($request->file('file'), 'categories/'.$category->id);
        $category->save();
        if ($old !== null) {
            Storage::disk(P::disk())->delete($old);
        }
        $this->audit('category.image_updated', 'category', $category->id, ['image_path' => $old], ['image_path' => $category->image_path]);
        $this->changed($category->id);

        return new JsonResponse(['data' => P::category($category)]);
    }

    public function destroyImage(int $id): Response
    {
        $category = Category::query()->findOrFail($id);
        if ($category->image_path !== null) {
            Storage::disk(P::disk())->delete($category->image_path);
            $this->audit('category.image_deleted', 'category', $category->id, ['image_path' => $category->image_path], ['image_path' => null]);
            $category->image_path = null;
            $category->save();
            $this->changed($category->id);
        }

        return response()->noContent();
    }

    /** @param  array<string, mixed>  $data */
    private function fill(Category $category, array $data, HtmlSanitizer $sanitizer): void
    {
        foreach (['name', 'parent_id', 'meta_title', 'meta_description', 'is_active', 'position'] as $key) {
            if (array_key_exists($key, $data)) {
                $category->{$key} = $data[$key];
            }
        }
        if (array_key_exists('description_html', $data)) {
            $category->description = $sanitizer->sanitize($data['description_html']);
        }
    }

    /** No cycle; resulting depth ≤ 3 (RN-CAT-007). */
    private function checkParent(?Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            if ($category !== null && app(CategoryTree::class)->subtreeHeight($category->id) > 3) {
                throw ValidationException::withMessages(['parent_id' => ['A árvore de categorias permite no máximo 3 níveis.']]);
            }

            return;
        }
        $tree = app(CategoryTree::class);
        if ($category !== null && ($parentId === $category->id || in_array($category->id, $tree->ancestorIds($parentId), true))) {
            throw ValidationException::withMessages(['parent_id' => ['Uma categoria não pode ser filha de si mesma ou de uma subcategoria.']]);
        }
        $height = $category !== null ? $tree->subtreeHeight($category->id) : 1;
        if ($tree->depth($parentId) + $height > 3) {
            throw ValidationException::withMessages(['parent_id' => ['A árvore de categorias permite no máximo 3 níveis.']]);
        }
    }

    /** RN-CAT-008: active children or primary category of an active product → 409. */
    private function ensureNotBlocked(Category $category, string $verb): void
    {
        $blockers = [];
        foreach (Category::query()->where('parent_id', $category->id)->where('is_active', true)->get(['id', 'name']) as $child) {
            $blockers[] = ['type' => 'category', 'id' => $child->id, 'label' => $child->name];
        }
        foreach (DB::table('products')->where('primary_category_id', $category->id)->where('is_active', true)->whereNull('deleted_at')->limit(50)->get(['id', 'name']) as $p) {
            $blockers[] = ['type' => 'product', 'id' => (int) $p->id, 'label' => $p->name];
        }
        if ($blockers !== []) {
            throw ResourceInUse::because("Não é possível {$verb} a categoria: há subcategorias ativas ou produtos ativos que a usam como principal.", $blockers);
        }
    }

    private function changed(?int $id): void
    {
        Cache::forget(StoreCategoryController::TREE_CACHE_KEY);
        CategoryTreeChanged::dispatch($id);
    }
}
