<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Events\ProductSaved;
use App\Modules\Catalog\Events\ProductSlugChanged;
use App\Modules\Catalog\Exceptions\StaleResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductActivation;
use App\Modules\Catalog\Support\RecordsAudit;
use App\Modules\Catalog\Support\SlugRules;
use App\Modules\Inventory\Contracts\InventoryRecords;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use App\Shared\Support\HtmlSanitizer;
use App\Shared\Support\PlainText;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates/updates a product with embedded variants upsert (API.md §3.G.5,
 * A-05). Field-level permissions: price fields need prices.manage,
 * initial_stock > 0 needs inventory.move, low_stock_threshold changes need
 * inventory.adjust (403 without effect).
 */
final class SaveProduct
{
    use RecordsAudit;

    private const array PRODUCT_AUDIT = ['name', 'slug', 'sale_unit', 'brand_id', 'primary_category_id', 'min_quantity', 'max_quantity',
        'quantity_step', 'min_billable_area_m2', 'fixed_width_mm', 'min_width_mm', 'max_width_mm', 'min_height_mm', 'max_height_mm',
        'is_active', 'is_featured', 'pickup_only', 'meta_title', 'meta_description'];

    private const array PRICE_FIELDS = ['price_cents', 'promo_price_cents', 'promo_starts_at', 'promo_ends_at', 'cost_cents'];

    private const array VARIANT_AUDIT = ['sku', 'name', 'price_cents', 'promo_price_cents', 'promo_starts_at', 'promo_ends_at', 'cost_cents',
        'weight_grams', 'is_active', 'position'];

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly InventoryService $inventory,
        private readonly InventoryRecords $records,
        private readonly ProductActivation $activation,
    ) {}

    /** @param  array<string, mixed>  $data  validated */
    public function handle(?int $productId, array $data, Authenticatable&Authorizable $admin): Product
    {
        $this->authorizeFields($productId, $data, $admin);

        [$product, $slugChange] = DB::transaction(function () use ($productId, $data, $admin) {
            $product = $productId !== null ? Product::query()->lockForUpdate()->findOrFail($productId) : new Product;
            $creating = ! $product->exists;
            if (! $creating) {
                StaleResource::check($data['expected_updated_at'] ?? null, $product->updated_at);
            }
            $before = $creating ? [] : self::snapshot($product);
            $oldSlug = $product->slug;

            $this->fillProduct($product, $data, $creating);
            $this->validateUnitRules($product);
            $wantsActive = (bool) ($data['is_active'] ?? ($creating ? false : $product->is_active));
            $product->is_active = $creating ? false : ($product->getOriginal('is_active') && $wantsActive);
            $product->save();

            $categoryIds = array_key_exists('category_ids', $data) ? array_map('intval', $data['category_ids']) : ($creating ? [] : $product->categories()->pluck('categories.id')->all());
            $categoryIds[] = $product->primary_category_id;
            $product->categories()->sync(array_fill_keys(array_values(array_unique($categoryIds)), ['position' => 0]));

            foreach ($data['variants'] ?? [] as $i => $input) {
                $this->saveVariant($product, (int) $i, $input, $admin);
            }

            $product->unsetRelation('variants')->unsetRelation('primaryCategory');
            if ($wantsActive) {
                $issues = $this->activation->issues($product);
                if ($issues !== []) {
                    throw ValidationException::withMessages(['is_active' => $issues]);
                }
                $product->is_active = true;
                $product->save();
            }

            $this->audit($creating ? 'product.created' : 'product.updated', 'product', $product->id, $before, self::snapshot($product));

            return [$product, ! $creating && $oldSlug !== $product->slug ? [$oldSlug, $product->slug] : null];
        });

        if ($slugChange !== null) {
            ProductSlugChanged::dispatch($product->id, $slugChange[0], $slugChange[1]);
        }
        ProductSaved::dispatch($product->id);

        return $product->fresh();
    }

    /** @param  array<string, mixed>  $data */
    private function authorizeFields(?int $productId, array $data, Authenticatable&Authorizable $admin): void
    {
        $existing = $productId !== null
            ? ProductVariant::query()->withTrashed()->where('product_id', $productId)->get()->keyBy('id')
            : collect();
        foreach ($data['variants'] ?? [] as $input) {
            $current = isset($input['id']) ? $existing[(int) $input['id']] ?? null : null;
            if (! $admin->can('prices.manage')) {
                foreach (self::PRICE_FIELDS as $field) {
                    if (! array_key_exists($field, $input)) {
                        continue;
                    }
                    $old = $current?->{$field};
                    $new = $input[$field];
                    if ($old instanceof \DateTimeInterface) {
                        $old = CarbonImmutable::instance($old)->utc()->toIso8601String();
                        $new = $new !== null ? CarbonImmutable::parse($new)->utc()->toIso8601String() : null;
                    }
                    if ($current === null || $old != $new) {
                        throw new AuthorizationException('Você não tem permissão para alterar preços.');
                    }
                }
            }
            if (isset($input['initial_stock']) && Quantity::fromString((string) $input['initial_stock'])->isPositive() && ! $admin->can('inventory.move')) {
                throw new AuthorizationException('Você não tem permissão para lançar estoque.');
            }
            if (array_key_exists('low_stock_threshold', $input) && ! $admin->can('inventory.adjust')) {
                $old = $current !== null ? DB::table('inventory')->where('variant_id', $current->id)->value('low_stock_threshold') : null;
                $oldQ = $old !== null ? Quantity::fromString((string) $old)->milli() : null;
                $newQ = $input['low_stock_threshold'] !== null ? Quantity::fromString((string) $input['low_stock_threshold'])->milli() : null;
                if ($current === null ? $newQ !== null : $oldQ !== $newQ) {
                    throw new AuthorizationException('Você não tem permissão para alterar o limiar de estoque.');
                }
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    private function fillProduct(Product $product, array $data, bool $creating): void
    {
        if (array_key_exists('sale_unit', $data) && ! $creating && $product->sale_unit->value !== $data['sale_unit']
            && DB::table('order_items')->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages(['sale_unit' => ['A unidade de venda não pode ser alterada: já existem pedidos com este produto.']]);
        }

        foreach (['name', 'sale_unit', 'brand_id', 'primary_category_id', 'meta_title', 'meta_description', 'is_featured', 'pickup_only'] as $key) {
            if (array_key_exists($key, $data)) {
                $product->{$key} = in_array($key, ['name', 'meta_title', 'meta_description'], true) ? PlainText::clean($data[$key]) : $data[$key];
            }
        }
        foreach (['min_quantity', 'max_quantity', 'quantity_step', 'min_billable_area_m2'] as $key) {
            if (array_key_exists($key, $data)) {
                $product->{$key} = $data[$key] !== null ? Quantity::fromString((string) $data[$key]) : null;
            }
        }
        foreach (['fixed_width' => 'fixed_width_m', 'min_width' => 'min_width_m', 'max_width' => 'max_width_m', 'min_height' => 'min_height_m', 'max_height' => 'max_height_m'] as $col => $key) {
            if (array_key_exists($key, $data)) {
                $product->{$col.'_mm'} = self::mm($data[$key], $key);
            }
        }
        if (array_key_exists('short_description', $data)) {
            $product->short_description = PlainText::clean($data['short_description']) ?: null;
        }
        if (array_key_exists('description_html', $data)) {
            $product->description = $this->sanitizer->sanitize($data['description_html']);
        }
        if (array_key_exists('specifications', $data)) {
            $product->specifications = array_values(array_map(fn (array $s) => [
                'label' => (string) PlainText::clean($s['label']), 'value' => (string) PlainText::clean($s['value']),
            ], $data['specifications'] ?? []));
        }
        if (! empty($data['slug'])) {
            $product->slug = $data['slug'];
        } elseif ($creating) {
            $product->slug = SlugRules::generate('products', (string) $product->name, null, 220);
        }
        if ($creating) {
            $product->min_quantity ??= Quantity::ofUnits(1);
            $product->quantity_step ??= Quantity::ofUnits(1);
        }
    }

    /** DATABASE.md §3.1.3 CHECKs + RN-QTD-003, reported as 422 before hitting the database. */
    private function validateUnitRules(Product $p): void
    {
        $e = [];
        $unit = $p->sale_unit;
        $integer = in_array($unit, [SaleUnit::Unit, SaleUnit::Roll, SaleUnit::Box, SaleUnit::SquareMeter], true);
        foreach (['min_quantity', 'quantity_step', 'max_quantity'] as $k) {
            $q = $p->{$k};
            if ($q !== null && ! $q->isPositive()) {
                $e[$k][] = 'Informe um valor maior que zero.';
            } elseif ($q !== null && $integer && ! $q->isInteger()) {
                $e[$k][] = 'Para esta unidade de venda o valor deve ser inteiro.';
            }
        }
        if ($e === []) {
            if (! $p->min_quantity->isMultipleOf($p->quantity_step)) {
                $e['min_quantity'][] = 'A quantidade mínima deve ser múltipla do passo.';
            }
            if ($p->max_quantity !== null && $p->max_quantity->lessThan($p->min_quantity)) {
                $e['max_quantity'][] = 'A quantidade máxima deve ser maior ou igual à mínima.';
            } elseif ($p->max_quantity !== null && ! $p->max_quantity->isMultipleOf($p->quantity_step)) {
                $e['max_quantity'][] = 'A quantidade máxima deve ser múltipla do passo.';
            }
        }
        if ($unit !== SaleUnit::SquareMeter) {
            foreach (['min_billable_area_m2' => 'min_billable_area_m2', 'min_width_mm' => 'min_width_m', 'max_width_mm' => 'max_width_m', 'min_height_mm' => 'min_height_m', 'max_height_mm' => 'max_height_m'] as $col => $field) {
                if ($p->{$col} !== null) {
                    $e[$field][] = 'Disponível apenas para produtos vendidos por m².';
                }
            }
        }
        if ($p->fixed_width_mm !== null && ! in_array($unit, [SaleUnit::SquareMeter, SaleUnit::LinearMeter], true)) {
            $e['fixed_width_m'][] = 'Largura fixa apenas para produtos por m² ou metro linear.';
        }
        if ($p->fixed_width_mm !== null && ($p->min_width_mm !== null || $p->max_width_mm !== null)) {
            $e['fixed_width_m'][] = 'Use largura fixa ou faixa de largura, não ambas.';
        }
        if ($p->min_billable_area_m2 !== null && ! $p->min_billable_area_m2->isPositive()) {
            $e['min_billable_area_m2'][] = 'Informe um valor maior que zero.';
        }
        foreach ([['min_width_mm', 'max_width_mm', 'max_width_m'], ['min_height_mm', 'max_height_mm', 'max_height_m']] as [$min, $max, $field]) {
            if ($p->{$min} !== null && $p->{$max} !== null && $p->{$max} < $p->{$min}) {
                $e[$field][] = 'O máximo deve ser maior ou igual ao mínimo.';
            }
        }
        if ($e !== []) {
            throw ValidationException::withMessages($e);
        }
    }

    /** @param  array<string, mixed>  $input */
    private function saveVariant(Product $product, int $index, array $input, Authenticatable&Authorizable $admin): void
    {
        $key = "variants.{$index}";
        $creating = ! isset($input['id']);
        if ($creating) {
            $variant = new ProductVariant(['product_id' => $product->id]);
            foreach (['price_cents', 'weight_grams'] as $required) {
                if (! array_key_exists($required, $input)) {
                    throw ValidationException::withMessages(["{$key}.{$required}" => ['Campo obrigatório para nova variante.']]);
                }
            }
        } else {
            $variant = ProductVariant::query()->where('product_id', $product->id)->lockForUpdate()->find((int) $input['id']);
            if ($variant === null) {
                throw ValidationException::withMessages(["{$key}.id" => ['Variante não pertence a este produto.']]);
            }
            if (array_key_exists('initial_stock', $input)) {
                throw ValidationException::withMessages(["{$key}.initial_stock" => ['Estoque inicial só pode ser informado em variantes novas.']]);
            }
        }
        $before = $creating ? [] : $variant->only(self::VARIANT_AUDIT);

        foreach (['sku', 'gtin', 'price_cents', 'promo_price_cents', 'promo_starts_at', 'promo_ends_at', 'cost_cents', 'weight_grams',
            'package_length_cm', 'package_width_cm', 'package_height_cm', 'roll_length_m', 'units_per_box', 'units_per_package', 'is_active', 'position'] as $field) {
            if (array_key_exists($field, $input)) {
                $variant->{$field} = $input[$field];
            }
        }
        if (array_key_exists('name', $input)) {
            $variant->name = (string) PlainText::clean($input['name']);
        }
        if (array_key_exists('attributes', $input)) {
            $attrs = [];
            foreach ($input['attributes'] ?? [] as $k => $v) {
                $k = (string) PlainText::clean((string) $k);
                if ($k === '' || mb_strlen($k) > 40) {
                    throw ValidationException::withMessages(["{$key}.attributes" => ['Chaves de atributo devem ter de 1 a 40 caracteres.']]);
                }
                $attrs[$k] = (string) PlainText::clean((string) $v);
            }
            $variant->setAttribute('attributes', $attrs);
        }
        if (array_key_exists('fixed_width_m', $input)) {
            if ($input['fixed_width_m'] !== null && ! in_array($product->sale_unit, [SaleUnit::SquareMeter, SaleUnit::LinearMeter], true)) {
                throw ValidationException::withMessages(["{$key}.fixed_width_m" => ['Largura fixa apenas para produtos por m² ou metro linear.']]);
            }
            $variant->fixed_width_mm = self::mm($input['fixed_width_m'], "{$key}.fixed_width_m");
        }
        if ($variant->promo_price_cents !== null && $variant->promo_price_cents >= $variant->price_cents) {
            throw ValidationException::withMessages(["{$key}.promo_price_cents" => ['O preço promocional deve ser menor que o preço.']]);
        }
        if ($variant->promo_starts_at !== null && $variant->promo_ends_at !== null && $variant->promo_ends_at <= $variant->promo_starts_at) {
            throw ValidationException::withMessages(["{$key}.promo_ends_at" => ['O fim da promoção deve ser posterior ao início.']]);
        }
        $variant->save();

        $threshold = array_key_exists('low_stock_threshold', $input) && $input['low_stock_threshold'] !== null
            ? Quantity::fromString((string) $input['low_stock_threshold']) : null;
        if ($creating) {
            $this->records->ensureRecord($variant->id, $threshold);
            if (isset($input['initial_stock']) && Quantity::fromString((string) $input['initial_stock'])->isPositive()) {
                $this->inventory->receive($variant->id, Quantity::fromString((string) $input['initial_stock']), 'Estoque inicial', ActorRef::admin((int) $admin->getAuthIdentifier()));
            }
        } elseif (array_key_exists('low_stock_threshold', $input)) {
            $this->records->setLowStockThreshold($variant->id, $threshold, ActorRef::admin((int) $admin->getAuthIdentifier()));
        }

        $this->audit($creating ? 'product_variant.created' : 'product_variant.updated', 'product_variant', $variant->id, $before, $variant->only(self::VARIANT_AUDIT));
    }

    /** @return array<string, mixed> */
    private static function snapshot(Product $p): array
    {
        $out = [];
        foreach (self::PRODUCT_AUDIT as $k) {
            $v = $p->{$k};
            $out[$k] = $v instanceof Quantity ? $v->toDecimalString() : ($v instanceof \BackedEnum ? $v->value : $v);
        }

        return $out;
    }

    private static function mm(mixed $meters, string $field): ?int
    {
        if ($meters === null) {
            return null;
        }
        $mm = Quantity::fromString((string) $meters)->milli();
        if ($mm <= 0 || $mm > 100_000) {
            throw ValidationException::withMessages([$field => ['Informe um valor entre 0,001 e 100 m.']]);
        }

        return $mm;
    }
}
