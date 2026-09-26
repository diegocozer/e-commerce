<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Contracts\CatalogQuery;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Contracts\VariantLabelProvider;
use App\Shared\Domain\Quantity;
use Illuminate\Support\Facades\DB;

final class CatalogQueryTest extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_variant_data_for_rolls_boxes_and_area(): void
    {
        $ids = ProductVariant::query()->whereIn('sku', ['VIN-BR-122-BR', 'LON-FL-440-120', 'PAP-A4-75-CX10', 'ADJ-122'])->pluck('id', 'sku');
        DB::table('product_variants')->where('id', $ids['VIN-BR-122-BR'])->update(['package_length_cm' => null, 'package_width_cm' => '10.0', 'package_height_cm' => '10.0']);

        DB::enableQueryLog();
        $data = app(CatalogQuery::class)->variants($ids->values()->all());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThanOrEqual(7, $queries);

        $vinyl = $data[$ids['VIN-BR-122-BR']];
        self::assertSame(1220, $vinyl->fixedWidthMm);
        self::assertSame([1220, 100, 100], [$vinyl->package?->lengthMm, $vinyl->package?->widthMm, $vinyl->package?->heightMm]);
        self::assertSame('brilho', $vinyl->attributes['acabamento']);
        self::assertSame('/vinis/vinil-adesivo-branco-122m', $vinyl->urlPath());
        self::assertNotNull($vinyl->image);
        self::assertSame(250, $vinyl->weightGrams);
        self::assertSame('in_stock', $vinyl->availabilityStatus(Quantity::ofUnits(500)));

        self::assertSame(1200, $data[$ids['LON-FL-440-120']]->fixedWidthMm); // variant override
        self::assertSame(1000, $data[$ids['LON-FL-440-120']]->minBillableArea?->milli());
        self::assertSame(10, $data[$ids['PAP-A4-75-CX10']]->unitsPerBox);
        self::assertSame('low_stock', $data[$ids['ADJ-122']]->availabilityStatus(Quantity::ofUnits(8)));
        self::assertSame('out_of_stock', $data[$ids['ADJ-122']]->availabilityStatus(Quantity::fromString('0.5')));

        $subject = app(CatalogQuery::class)->pricingSubject($ids['VIN-BR-122-BR']);
        self::assertSame(1590, $subject->basePrice->cents());
        self::assertContains(DB::table('categories')->where('slug', 'vinis')->value('id'), $subject->categoryIdsWithAncestors);
        self::assertNull(app(CatalogQuery::class)->variantBySlug('outro-produto', $ids['VIN-BR-122-BR']));
    }

    public function test_variant_label_provider(): void
    {
        $provider = app(VariantLabelProvider::class);
        $id = (int) ProductVariant::query()->where('sku', 'ILH-0-LAT')->value('id');
        self::assertSame('ILH-0-LAT', $provider->labels([$id])[$id]['sku']);
        self::assertContains($id, $provider->search(['q' => 'ILH-0']));
        self::assertContains($id, $provider->search(['q' => 'ilhos']));
        self::assertNotContains($id, $provider->search(['sale_unit' => ['BOX']]));
    }
}
