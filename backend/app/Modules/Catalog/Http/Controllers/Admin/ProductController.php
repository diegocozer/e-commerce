<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Events\ProductDeleted;
use App\Modules\Catalog\Events\ProductSaved;
use App\Modules\Catalog\Exceptions\ResourceInUse;
use App\Modules\Catalog\Http\Requests\Admin\ProductRequest;
use App\Modules\Catalog\Http\Resources\Admin\AdminCatalogPresenter as P;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductActivation;
use App\Modules\Catalog\Support\CategoryTree;
use App\Modules\Catalog\Support\RecordsAudit;
use App\Modules\Catalog\Support\SlugRules;
use App\Shared\Domain\SaleUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ProductController
{
    use RecordsAudit;

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'category_id' => ['sometimes', 'integer'],
            'brand_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'all'])],
            'sale_unit' => ['sometimes', Rule::enum(SaleUnit::class)],
            'low_stock' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'include_deleted' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['name', '-name', 'created_at', '-created_at', 'updated_at', '-updated_at', 'min_price', '-min_price'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()->select('products.*')
            ->when($request->boolean('include_deleted'), fn ($q) => $q->withTrashed())
            ->with(['variants', 'images', 'brand', 'primaryCategory']);
        if (isset($f['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']).'%';
            $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtoupper($f['q'])).'%';
            $query->where(fn ($w) => $w->whereRaw('public.f_unaccent(products.name) ILIKE public.f_unaccent(?)', [$like])
                ->orWhereExists(fn ($e) => $e->from('product_variants as qv')->whereColumn('qv.product_id', 'products.id')->where('qv.sku', 'like', $prefix)));
        }
        if (isset($f['category_id'])) {
            $ids = app(CategoryTree::class)->descendantIds((int) $f['category_id']);
            $query->whereExists(fn ($e) => $e->from('product_categories as cpc')->whereColumn('cpc.product_id', 'products.id')->whereIn('cpc.category_id', $ids));
        }
        $query->when(isset($f['brand_id']), fn ($q) => $q->where('brand_id', $f['brand_id']))
            ->when(isset($f['sale_unit']), fn ($q) => $q->where('sale_unit', $f['sale_unit']))
            ->when(($f['status'] ?? 'all') !== 'all', fn ($q) => $q->where('is_active', $f['status'] === 'active'))
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true));
        if ($request->boolean('low_stock')) {
            $default = (string) app(\App\Modules\Inventory\Contracts\InventoryRecords::class)->defaultLowStockThreshold()->toDecimalString();
            $query->whereExists(fn ($e) => $e->from('product_variants as lv')->join('inventory as li', 'li.variant_id', '=', 'lv.id')
                ->whereColumn('lv.product_id', 'products.id')->whereNull('lv.deleted_at')->where('lv.is_active', true)
                ->whereRaw('(li.on_hand - li.reserved) <= coalesce(li.low_stock_threshold, ?)', [$default]));
        }
        $sort = $f['sort'] ?? '-updated_at';
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-');
        if ($col === 'min_price') {
            $query->orderByRaw("(SELECT min(mv.price_cents) FROM product_variants mv WHERE mv.product_id = products.id AND mv.deleted_at IS NULL) {$dir}");
        } else {
            $query->orderBy('products.'.$col, $dir);
        }
        $page = $query->orderBy('products.id')->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return new JsonResponse(P::paginated($page, P::productListItems($page->getCollection())));
    }

    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['data' => P::product(Product::query()->withTrashed()->findOrFail($id))]);
    }

    public function store(ProductRequest $request, SaveProduct $action): JsonResponse
    {
        $product = $action->handle(null, $request->validated(), $request->user('admin'));

        return new JsonResponse(['data' => P::product($product)], 201);
    }

    public function update(ProductRequest $request, SaveProduct $action, int $id): JsonResponse
    {
        $product = $action->handle($id, $request->validated(), $request->user('admin'));

        return new JsonResponse(['data' => P::product($product)]);
    }

    public function destroy(int $id): Response
    {
        DB::transaction(function () use ($id): void {
            $product = Product::query()->lockForUpdate()->findOrFail($id);
            ProductVariant::query()->where('product_id', $product->id)->get()->each->delete();
            $product->delete();
            $this->audit('product.deleted', 'product', $product->id, ['name' => $product->name, 'slug' => $product->slug], []);
        });
        ProductDeleted::dispatch($id);

        return response()->noContent();
    }

    public function destroyVariant(int $id, int $variantId): Response
    {
        DB::transaction(function () use ($id, $variantId): void {
            $product = Product::query()->lockForUpdate()->findOrFail($id);
            $variant = ProductVariant::query()->where('product_id', $product->id)->findOrFail($variantId);
            $othersActive = ProductVariant::query()->where('product_id', $product->id)->whereKeyNot($variant->id)->where('is_active', true)->exists();
            if ($product->is_active && ! $othersActive) {
                throw ResourceInUse::because('Não é possível excluir a última variante ativa de um produto ativo.', [
                    ['type' => 'product', 'id' => $product->id, 'label' => $product->name],
                ]);
            }
            $variant->delete();
            $this->audit('product_variant.deleted', 'product_variant', $variant->id, ['sku' => $variant->sku], []);
        });
        ProductSaved::dispatch($id);

        return response()->noContent();
    }

    public function bulk(Request $request, ProductActivation $activation): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete', 'set_primary_category'])],
            'category_id' => ['required_if:action,set_primary_category', 'nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
        ]);
        $succeeded = [];
        $failed = [];
        foreach ($data['ids'] as $id) {
            $product = Product::query()->find($id);
            if ($product === null) {
                $failed[] = ['id' => (int) $id, 'errors' => ['Produto não encontrado.']];

                continue;
            }
            $errors = DB::transaction(function () use ($product, $data, $activation): array {
                $before = ['is_active' => $product->is_active, 'primary_category_id' => $product->primary_category_id];
                switch ($data['action']) {
                    case 'activate':
                        $issues = $activation->issues($product);
                        if ($issues !== []) {
                            return $issues;
                        }
                        $product->is_active = true;
                        $product->save();
                        break;
                    case 'deactivate':
                        $product->is_active = false;
                        $product->save();
                        break;
                    case 'delete':
                        ProductVariant::query()->where('product_id', $product->id)->get()->each->delete();
                        $product->delete();
                        break;
                    default:
                        $product->primary_category_id = (int) $data['category_id'];
                        $product->save();
                        $product->categories()->syncWithoutDetaching([(int) $data['category_id'] => ['position' => 0]]);
                }
                $this->audit('product.bulk_'.$data['action'], 'product', $product->id, $before, ['is_active' => $product->is_active, 'primary_category_id' => $product->primary_category_id]);

                return [];
            });
            if ($errors !== []) {
                $failed[] = ['id' => $product->id, 'errors' => $errors];
            } else {
                $succeeded[] = $product->id;
                $data['action'] === 'delete' ? ProductDeleted::dispatch($product->id) : ProductSaved::dispatch($product->id);
            }
        }

        return new JsonResponse(['data' => ['succeeded' => $succeeded, 'failed' => $failed]]);
    }

    public function slugAvailability(Request $request): JsonResponse
    {
        $f = $request->validate(['slug' => ['required', 'string', 'max:220'], 'ignore_id' => ['sometimes', 'integer']]);
        $slug = $f['slug'];
        $valid = preg_match(SlugRules::REGEX, $slug) === 1 && ! in_array($slug, config('catalog.reserved_slugs'), true);
        $taken = Product::query()->where('slug', $slug)->when(isset($f['ignore_id']), fn ($q) => $q->whereKeyNot($f['ignore_id']))->exists();
        $available = $valid && ! $taken;

        return new JsonResponse(['data' => [
            'available' => $available,
            'suggestion' => $available ? null : SlugRules::generate('products', $slug, isset($f['ignore_id']) ? (int) $f['ignore_id'] : null, 220),
        ]]);
    }

    public function variantPicker(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'product_id' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ProductVariant::query()->with('product')->whereHas('product')->orderBy('sku');
        if (isset($f['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']).'%';
            $query->where(fn ($w) => $w->where('sku', 'like', mb_strtoupper(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q'])).'%')
                ->orWhereRaw('public.f_unaccent(name) ILIKE public.f_unaccent(?)', [$like])
                ->orWhereHas('product', fn ($p) => $p->whereRaw('public.f_unaccent(name) ILIKE public.f_unaccent(?)', [$like])));
        }
        $query->when(isset($f['product_id']), fn ($q) => $q->where('product_id', $f['product_id']))
            ->when(isset($f['is_active']), fn ($q) => $q->where('is_active', (bool) $f['is_active']));
        $page = $query->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return new JsonResponse(P::paginated($page, $page->getCollection()->map(fn (ProductVariant $v) => self::pickerItem($v))->all()));
    }

    public function skuAvailability(Request $request): JsonResponse
    {
        $f = $request->validate(['sku' => ['required', 'string', 'max:40'], 'ignore_id' => ['sometimes', 'integer']]);
        $variant = ProductVariant::query()->withTrashed()->with(['product' => fn ($q) => $q->withTrashed()])
            ->where('sku', mb_strtoupper(trim($f['sku'])))
            ->when(isset($f['ignore_id']), fn ($q) => $q->whereKeyNot($f['ignore_id']))->first();

        return new JsonResponse(['data' => [
            'available' => $variant === null,
            'used_by' => $variant !== null ? ['product_id' => $variant->product_id, 'product_name' => $variant->product?->name] : null,
        ]]);
    }

    /** @return array<string, mixed> AdminVariantPickerItem */
    public static function pickerItem(ProductVariant $v): array
    {
        return [
            'id' => $v->id, 'sku' => $v->sku, 'name' => $v->name, 'sale_unit' => $v->product?->sale_unit->value,
            'price_cents' => $v->price_cents, 'is_active' => $v->is_active,
            'product' => ['id' => $v->product_id, 'name' => $v->product?->name],
        ];
    }

    public static function categoryExists(int $id): bool
    {
        return Category::query()->whereKey($id)->exists();
    }
}
