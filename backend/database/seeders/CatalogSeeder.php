<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\InventoryMovementType;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DATABASE.md §7.4–§7.6: categories, brands, 21 products covering every sale
 * unit, variants, initial stock ("in" movement "Carga inicial") and the
 * full-text search vector.
 */
class CatalogSeeder extends Seeder
{
    private const array CATEGORIES = [
        ['lonas', 'Lonas'], ['vinis', 'Vinis'], ['adesivos', 'Adesivos'], ['papeis', 'Papéis'], ['bobinas', 'Bobinas'],
        ['tintas', 'Tintas'], ['fitas', 'Fitas'], ['ilhos', 'Ilhós'], ['ferramentas', 'Ferramentas'], ['acessorios', 'Acessórios'],
    ];

    private const array SUBCATEGORIES = [
        ['vinil-adesivo', 'Vinil Adesivo', 'vinis'],
        ['vinil-transparente', 'Vinil Transparente', 'vinis'],
        ['tintas-eco-solventes', 'Tintas Eco-solventes', 'tintas'],
        ['tintas-serigraficas', 'Tintas Serigráficas', 'tintas'],
    ];

    private const array BRANDS = [
        'imprimax' => 'Imprimax', 'vinilsul' => 'VinilSul', 'coloraco' => 'Coloraço', 'papelnorte' => 'PapelNorte', 'ferrovale' => 'FerroVale',
    ];

    /** @var array<string, int> */
    private array $categoryIds = [];

    /** @var array<string, int> */
    private array $brandIds = [];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedCategories();
            $this->seedBrands();
            foreach ($this->products() as $position => $data) {
                $this->seedProduct($data, $position);
            }
        });

        $this->refreshSearchVectors();
    }

    private function seedCategories(): void
    {
        foreach (self::CATEGORIES as $index => [$slug, $name]) {
            $this->categoryIds[$slug] = Category::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'parent_id' => null, 'position' => $index + 1, 'is_active' => true,
                    'description' => "<p>{$name} para comunicação visual.</p>"],
            )->id;
        }

        foreach (self::SUBCATEGORIES as $index => [$slug, $name, $parent]) {
            $this->categoryIds[$slug] = Category::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'parent_id' => $this->categoryIds[$parent], 'position' => $index + 1, 'is_active' => true],
            )->id;
        }
    }

    private function seedBrands(): void
    {
        foreach (self::BRANDS as $slug => $name) {
            $this->brandIds[$slug] = Brand::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true])->id;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function seedProduct(array $data, int $position): void
    {
        $categories = $data['categories'];
        $product = Product::query()->updateOrCreate(['slug' => $data['slug']], [
            'name' => $data['name'],
            'short_description' => $data['short'] ?? null,
            'description' => '<p>'.($data['short'] ?? $data['name']).'</p>',
            'sale_unit' => $data['unit'],
            'brand_id' => $this->brandIds[$data['brand']],
            'primary_category_id' => $this->categoryIds[$categories[0]],
            'min_quantity' => $data['min'] ?? '1',
            'max_quantity' => $data['max'] ?? null,
            'quantity_step' => $data['step'] ?? '1',
            'min_billable_area_m2' => $data['min_area'] ?? null,
            'fixed_width_mm' => $data['fixed_width'] ?? null,
            'min_width_mm' => $data['width'][0] ?? null,
            'max_width_mm' => $data['width'][1] ?? null,
            'min_height_mm' => $data['height'][0] ?? null,
            'max_height_mm' => $data['height'][1] ?? null,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'specifications' => $data['specifications'] ?? null,
            'is_active' => true,
            'is_featured' => $data['featured'] ?? false,
            'pickup_only' => false,
        ]);

        $product->categories()->sync(collect($categories)->mapWithKeys(
            fn (string $slug, int $i): array => [$this->categoryIds[$slug] => ['position' => $position * 10 + $i]],
        )->all());

        ProductImage::query()->updateOrCreate(
            ['product_id' => $product->id, 'position' => 0],
            ['disk' => 'public', 'path' => 'products/placeholder.webp', 'alt' => $product->name, 'width_px' => 800, 'height_px' => 800],
        );

        foreach ($data['variants'] as $variantPosition => $variant) {
            $this->seedVariant($product, $variant, $variantPosition);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function seedVariant(Product $product, array $data, int $position): void
    {
        [$length, $width, $height] = $data['package'] ?? [null, null, null];

        $variant = ProductVariant::query()->updateOrCreate(['sku' => $data['sku']], [
            'product_id' => $product->id,
            'name' => $data['name'],
            'attributes' => $data['attributes'] ?? [],
            'price_cents' => $data['price'],
            'weight_grams' => $data['weight'],
            'package_length_cm' => $length,
            'package_width_cm' => $width,
            'package_height_cm' => $height,
            'roll_length_m' => $data['roll_length'] ?? null,
            'units_per_box' => $data['units_per_box'] ?? null,
            'fixed_width_mm' => $data['fixed_width'] ?? null,
            'is_active' => true,
            'position' => $position,
        ]);

        if (Inventory::query()->where('variant_id', $variant->id)->exists()) {
            return; // idempotent: initial stock only once
        }

        $onHand = Quantity::fromString($data['stock']);
        $inventory = new Inventory(['variant_id' => $variant->id, 'low_stock_threshold' => $data['alert']]);
        $inventory->on_hand = $onHand;
        $inventory->reserved = Quantity::zero();
        $inventory->save();

        if ($onHand->isPositive()) {
            InventoryMovement::query()->create([
                'variant_id' => $variant->id,
                'type' => InventoryMovementType::In,
                'quantity' => $onHand,
                'on_hand_delta' => $onHand,
                'reserved_delta' => Quantity::zero(),
                'on_hand_after' => $onHand,
                'reserved_after' => Quantity::zero(),
                'reason' => 'Carga inicial',
            ]);
        }
    }

    private function refreshSearchVectors(): void
    {
        DB::statement(<<<'SQL'
            UPDATE products p SET search_vector =
                setweight(to_tsvector('public.pt_unaccent', coalesce(p.name, '')), 'A') ||
                setweight(to_tsvector('public.pt_unaccent', coalesce(b.name, '')), 'B') ||
                setweight(to_tsvector('public.pt_unaccent', coalesce(cats.names, '')), 'B') ||
                setweight(to_tsvector('public.pt_unaccent', coalesce(p.short_description, '')), 'C') ||
                setweight(to_tsvector('public.pt_unaccent', coalesce(regexp_replace(p.description, '<[^>]+>', ' ', 'g'), '')), 'D')
            FROM products p2
            LEFT JOIN brands b ON b.id = p2.brand_id
            LEFT JOIN LATERAL (
                SELECT string_agg(c.name, ' ') AS names
                FROM product_categories pc JOIN categories c ON c.id = pc.category_id
                WHERE pc.product_id = p2.id
            ) cats ON true
            WHERE p2.id = p.id
            SQL);
    }

    /** @return list<array<string, mixed>> */
    private function products(): array
    {
        $roll = ['15.0', '15.0'];

        return [
            [
                'slug' => 'vinil-adesivo-branco-122m', 'name' => 'Vinil Adesivo Branco', 'categories' => ['vinis', 'vinil-adesivo', 'adesivos'],
                'brand' => 'vinilsul', 'unit' => SaleUnit::LinearMeter, 'fixed_width' => 1220, 'min' => '1', 'step' => '0.100', 'max' => '50',
                'featured' => true, 'short' => 'Vinil adesivo branco para recorte e impressão, largura 1,22 m, vendido por metro linear.',
                'meta_title' => 'Vinil Adesivo Branco 1,22 m | CV Suprimentos',
                'meta_description' => 'Vinil adesivo branco brilho ou fosco, largura 1,22 m, vendido por metro. Entrega em Blumenau e região.',
                'specifications' => [['label' => 'Largura', 'value' => '1,22 m'], ['label' => 'Espessura', 'value' => '0,08 mm'], ['label' => 'Durabilidade externa', 'value' => '3 anos']],
                'variants' => [
                    ['sku' => 'VIN-BR-122-BR', 'name' => 'Brilho', 'price' => 1590, 'weight' => 250, 'stock' => '500', 'alert' => '50',
                        'attributes' => ['cor' => 'branco', 'acabamento' => 'brilho', 'largura' => '1,22 m'], 'package' => [null, ...$roll], 'roll_length' => '50'],
                    ['sku' => 'VIN-BR-122-FO', 'name' => 'Fosco', 'price' => 1690, 'weight' => 250, 'stock' => '300', 'alert' => '50',
                        'attributes' => ['cor' => 'branco', 'acabamento' => 'fosco', 'largura' => '1,22 m'], 'package' => [null, ...$roll], 'roll_length' => '50'],
                ],
            ],
            [
                'slug' => 'vinil-adesivo-preto-fosco-122m', 'name' => 'Vinil Adesivo Preto Fosco 1,22 m', 'categories' => ['vinis', 'vinil-adesivo'],
                'brand' => 'vinilsul', 'unit' => SaleUnit::LinearMeter, 'fixed_width' => 1220, 'step' => '0.100',
                'variants' => [['sku' => 'VIN-PT-122-FO', 'name' => 'Padrão', 'price' => 1690, 'weight' => 250, 'stock' => '200', 'alert' => '30',
                    'attributes' => ['cor' => 'preto', 'acabamento' => 'fosco', 'largura' => '1,22 m'], 'package' => [null, ...$roll], 'roll_length' => '50']],
            ],
            [
                'slug' => 'vinil-transparente-100m', 'name' => 'Vinil Transparente 1,00 m', 'categories' => ['vinis', 'vinil-transparente'],
                'brand' => 'vinilsul', 'unit' => SaleUnit::LinearMeter, 'fixed_width' => 1000, 'step' => '0.500',
                'variants' => [['sku' => 'VIN-TR-100', 'name' => 'Padrão', 'price' => 1890, 'weight' => 220, 'stock' => '120', 'alert' => '20',
                    'attributes' => ['cor' => 'transparente', 'largura' => '1,00 m'], 'package' => [null, ...$roll], 'roll_length' => '50']],
            ],
            [
                'slug' => 'lona-frontlight-440g', 'name' => 'Lona Frontlight 440g', 'categories' => ['lonas'], 'brand' => 'imprimax',
                'unit' => SaleUnit::SquareMeter, 'min_area' => '1.000', 'width' => [300, 3200], 'height' => [300, 50000], 'max' => '100',
                'featured' => true, 'short' => 'Lona frontlight 440 g/m² para banners e fachadas, cortada sob medida.',
                'meta_title' => 'Lona Frontlight 440g sob medida | CV Suprimentos',
                'meta_description' => 'Lona frontlight 440 g/m² cortada sob medida, cobrança por m² com área mínima de 1 m² por peça.',
                'variants' => [
                    ['sku' => 'LON-FL-440-SM', 'name' => 'Sob medida', 'price' => 3000, 'weight' => 460, 'stock' => '800', 'alert' => '100',
                        'package' => [null, '20.0', '20.0'], 'roll_length' => '50'],
                    ['sku' => 'LON-FL-440-120', 'name' => 'Largura 1,20 m', 'price' => 3000, 'weight' => 460, 'stock' => '400', 'alert' => '50',
                        'fixed_width' => 1200, 'attributes' => ['largura' => '1,20 m'], 'package' => [null, '20.0', '20.0'], 'roll_length' => '50'],
                ],
            ],
            [
                'slug' => 'lona-backlight-500g', 'name' => 'Lona Backlight 500g', 'categories' => ['lonas'], 'brand' => 'imprimax',
                'unit' => SaleUnit::SquareMeter, 'min_area' => '0.500', 'width' => [300, 3200], 'height' => [300, 30000],
                'variants' => [['sku' => 'LON-BL-500', 'name' => 'Padrão', 'price' => 4500, 'weight' => 520, 'stock' => '150', 'alert' => '30',
                    'package' => [null, '20.0', '20.0'], 'roll_length' => '50']],
            ],
            [
                'slug' => 'adesivo-perfurado-one-way', 'name' => 'Adesivo Perfurado One Way 1,37 m', 'categories' => ['adesivos'], 'brand' => 'vinilsul',
                'unit' => SaleUnit::SquareMeter, 'fixed_width' => 1370, 'height' => [200, 50000], 'min_area' => '0.500',
                'variants' => [['sku' => 'ADP-OW-137', 'name' => 'Padrão', 'price' => 5500, 'weight' => 300, 'stock' => '90', 'alert' => '20',
                    'package' => [null, ...$roll], 'roll_length' => '50']],
            ],
            [
                'slug' => 'adesivo-jateado-122m', 'name' => 'Adesivo Jateado 1,22 m', 'categories' => ['adesivos'], 'brand' => 'vinilsul',
                'unit' => SaleUnit::LinearMeter, 'fixed_width' => 1220, 'step' => '0.100',
                'variants' => [['sku' => 'ADJ-122', 'name' => 'Padrão', 'price' => 2190, 'weight' => 240, 'stock' => '8', 'alert' => '20',
                    'package' => [null, ...$roll], 'roll_length' => '50']],
            ],
            [
                'slug' => 'mascara-transferencia-100m', 'name' => 'Máscara de Transferência 1,00 m', 'categories' => ['adesivos', 'acessorios'],
                'brand' => 'vinilsul', 'unit' => SaleUnit::LinearMeter, 'fixed_width' => 1000, 'step' => '0.500',
                'variants' => [['sku' => 'MTR-100', 'name' => 'Padrão', 'price' => 690, 'weight' => 80, 'stock' => '400', 'alert' => '50',
                    'package' => [null, ...$roll], 'roll_length' => '100']],
            ],
            [
                'slug' => 'bobina-papel-sulfite-90g', 'name' => 'Bobina Papel Sulfite 90g', 'categories' => ['bobinas', 'papeis'], 'brand' => 'papelnorte',
                'unit' => SaleUnit::Roll, 'max' => '20', 'featured' => true,
                'variants' => [
                    ['sku' => 'BOB-SUL90-914', 'name' => '914 mm × 50 m', 'price' => 35000, 'weight' => 4500, 'stock' => '40', 'alert' => '5',
                        'roll_length' => '50', 'package' => ['95.0', '12.0', '12.0'], 'attributes' => ['largura' => '914 mm', 'comprimento' => '50 m']],
                    ['sku' => 'BOB-SUL90-610', 'name' => '610 mm × 50 m', 'price' => 25900, 'weight' => 3000, 'stock' => '25', 'alert' => '5',
                        'roll_length' => '50', 'package' => ['64.0', '12.0', '12.0'], 'attributes' => ['largura' => '610 mm', 'comprimento' => '50 m']],
                ],
            ],
            [
                'slug' => 'bobina-papel-kraft-80g', 'name' => 'Bobina Papel Kraft 80g 1,20 m', 'categories' => ['bobinas', 'papeis'], 'brand' => 'papelnorte',
                'unit' => SaleUnit::Roll,
                'variants' => [['sku' => 'BOB-KRA80-120', 'name' => '1,20 m × 100 m', 'price' => 18900, 'weight' => 10200, 'stock' => '12', 'alert' => '3',
                    'roll_length' => '100', 'package' => ['125.0', '18.0', '18.0']]],
            ],
            [
                'slug' => 'papel-sulfite-a4-75g-caixa', 'name' => 'Papel Sulfite A4 75g — Caixa 10 resmas', 'categories' => ['papeis'],
                'brand' => 'papelnorte', 'unit' => SaleUnit::Box,
                'variants' => [['sku' => 'PAP-A4-75-CX10', 'name' => 'Padrão', 'price' => 29900, 'weight' => 24000, 'stock' => '60', 'alert' => '10',
                    'units_per_box' => 10, 'package' => ['45.0', '30.0', '22.0']]],
            ],
            [
                'slug' => 'tinta-eco-solvente-1l', 'name' => 'Tinta Eco-solvente 1 L', 'categories' => ['tintas', 'tintas-eco-solventes'], 'brand' => 'coloraco',
                'unit' => SaleUnit::Unit, 'max' => '24', 'featured' => true,
                'variants' => array_map(static fn (array $c): array => [
                    'sku' => 'TIN-ECO-1L-'.$c[0], 'name' => $c[1], 'price' => 32900, 'weight' => 1150, 'stock' => $c[0] === 'K' ? '0' : '20',
                    'alert' => '4', 'attributes' => ['cor' => mb_strtolower($c[1])], 'package' => ['10.0', '10.0', '22.0'],
                ], [['C', 'Ciano'], ['M', 'Magenta'], ['Y', 'Amarelo'], ['K', 'Preto']]),
            ],
            [
                'slug' => 'tinta-plastisol-branca', 'name' => 'Tinta Serigráfica Plastisol Branca', 'categories' => ['tintas', 'tintas-serigraficas'],
                'brand' => 'coloraco', 'unit' => SaleUnit::Kg, 'min' => '0.500', 'step' => '0.500', 'max' => '25',
                'variants' => [['sku' => 'TIN-PLA-BR', 'name' => 'Padrão', 'price' => 8990, 'weight' => 1050, 'stock' => '45', 'alert' => '10',
                    'package' => ['15.0', '15.0', '15.0']]],
            ],
            [
                'slug' => 'ilhos-n0-latao', 'name' => 'Ilhós nº 0 latão', 'categories' => ['ilhos'], 'brand' => 'ferrovale',
                'unit' => SaleUnit::Unit, 'min' => '10', 'step' => '10',
                'variants' => [['sku' => 'ILH-0-LAT', 'name' => 'Padrão', 'price' => 50, 'weight' => 1, 'stock' => '20000', 'alert' => '2000',
                    'package' => ['1.0', '1.0', '1.0']]],
            ],
            [
                'slug' => 'ilhos-n0-latao-caixa-1000', 'name' => 'Ilhós nº 0 latão — Caixa 1000 un', 'categories' => ['ilhos'], 'brand' => 'ferrovale',
                'unit' => SaleUnit::Box,
                'variants' => [['sku' => 'ILH-0-LAT-CX1000', 'name' => 'Padrão', 'price' => 38000, 'weight' => 1100, 'stock' => '50', 'alert' => '10',
                    'units_per_box' => 1000, 'package' => ['20.0', '15.0', '10.0']]],
            ],
            [
                'slug' => 'fita-dupla-face-19mm', 'name' => 'Fita Dupla Face 19 mm × 20 m', 'categories' => ['fitas'], 'brand' => 'imprimax', 'unit' => SaleUnit::Unit,
                'variants' => [['sku' => 'FIT-DF-19', 'name' => 'Padrão', 'price' => 1290, 'weight' => 60, 'stock' => '300', 'alert' => '50', 'package' => ['10.0', '10.0', '2.0']]],
            ],
            [
                'slug' => 'fita-vhb-12mm', 'name' => 'Fita VHB 12 mm × 20 m', 'categories' => ['fitas'], 'brand' => 'imprimax', 'unit' => SaleUnit::Unit,
                'variants' => [['sku' => 'FIT-VHB-12', 'name' => 'Padrão', 'price' => 8900, 'weight' => 90, 'stock' => '80', 'alert' => '10', 'package' => ['10.0', '10.0', '2.0']]],
            ],
            [
                'slug' => 'espatula-feltro', 'name' => 'Espátula de Feltro', 'categories' => ['ferramentas'], 'brand' => 'ferrovale', 'unit' => SaleUnit::Unit,
                'variants' => [['sku' => 'FER-ESP-FEL', 'name' => 'Padrão', 'price' => 890, 'weight' => 30, 'stock' => '500', 'alert' => '50', 'package' => ['12.0', '8.0', '1.0']]],
            ],
            [
                'slug' => 'estilete-profissional-18mm', 'name' => 'Estilete Profissional 18 mm', 'categories' => ['ferramentas'], 'brand' => 'ferrovale', 'unit' => SaleUnit::Unit,
                'variants' => [['sku' => 'FER-EST-18', 'name' => 'Padrão', 'price' => 2490, 'weight' => 120, 'stock' => '150', 'alert' => '20', 'package' => ['18.0', '5.0', '3.0']]],
            ],
            [
                'slug' => 'aplicador-ilhos-manual', 'name' => 'Aplicador de Ilhós Manual', 'categories' => ['ferramentas', 'ilhos'], 'brand' => 'ferrovale', 'unit' => SaleUnit::Unit,
                'variants' => [['sku' => 'FER-APL-ILH', 'name' => 'Padrão', 'price' => 18900, 'weight' => 1800, 'stock' => '15', 'alert' => '3', 'package' => ['30.0', '20.0', '10.0']]],
            ],
            [
                'slug' => 'kit-bastao-banner-1m', 'name' => 'Kit Bastão + Ponteiras para Banner 1 m', 'categories' => ['acessorios'], 'brand' => 'ferrovale', 'unit' => SaleUnit::Unit,
                'variants' => [['sku' => 'ACE-BAS-100', 'name' => 'Padrão', 'price' => 690, 'weight' => 150, 'stock' => '400', 'alert' => '50', 'package' => ['105.0', '3.0', '3.0']]],
            ],
        ];
    }
}
