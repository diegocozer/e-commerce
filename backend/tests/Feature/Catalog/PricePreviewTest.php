<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Pricing\Models\Promotion;

/** API.md §4.2 / §4.3. */
final class PricePreviewTest extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function id(string $sku): int
    {
        return (int) ProductVariant::query()->where('sku', $sku)->value('id');
    }

    public function test_vinyl_5m(): void
    {
        $this->postJson('/api/v1/products/vinil-adesivo-branco-122m/price-preview', ['variant_id' => $this->id('VIN-BR-122-BR'), 'quantity' => 5])
            ->assertOk()
            ->assertJsonPath('data.configuration_label', '5 m')
            ->assertJsonPath('data.billable_quantity', 5)
            ->assertJsonPath('data.unit_price_cents', 1590)
            ->assertJsonPath('data.line_total_cents', 7950)
            ->assertJsonPath('data.weight_grams', 1250)
            ->assertJsonPath('data.applied_tier', ['min_quantity' => 1, 'max_quantity' => 9.9, 'unit_price_cents' => 1590, 'price_source' => 'base'])
            ->assertJsonPath('data.next_tier.missing_quantity', 5)
            ->assertJsonPath('data.stock', ['sufficient' => true, 'available_quantity' => null]);
    }

    public function test_off_step_returns_suggestions(): void
    {
        $this->postJson('/api/v1/products/vinil-adesivo-branco-122m/price-preview', ['variant_id' => $this->id('VIN-BR-122-BR'), 'quantity' => '5.05'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m.')
            ->assertJsonPath('errors.quantity.0', 'Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m.')
            ->assertJsonPath('details.quantity.suggestions', [5, 5.1]);
    }

    public function test_lona_area_and_minimum(): void
    {
        Promotion::query()->delete();
        $id = $this->id('LON-FL-440-SM');
        $this->postJson('/api/v1/products/lona-frontlight-440g/price-preview', ['variant_id' => $id, 'width_m' => 1.2, 'height_m' => 2.5, 'pieces' => 1])
            ->assertOk()
            ->assertJsonPath('data.configuration_label', '1,20 m × 2,50 m × 1 peça')
            ->assertJsonPath('data.area_m2', 3)
            ->assertJsonPath('data.line_total_cents', 9000)
            ->assertJsonPath('data.weight_grams', 1380)
            ->assertJsonPath('data.applied_tier.min_quantity', 0.001)
            ->assertJsonPath('data.applied_tier.max_quantity', 19.999)
            ->assertJsonPath('data.next_tier.missing_quantity', 17);

        $this->postJson('/api/v1/products/lona-frontlight-440g/price-preview', ['variant_id' => $id, 'width_m' => 0.4, 'height_m' => 0.5, 'pieces' => 1])
            ->assertOk()
            ->assertJsonPath('data.piece_area_m2', 0.2)
            ->assertJsonPath('data.billable_quantity', 1)
            ->assertJsonPath('data.stock_quantity', 0.2)
            ->assertJsonPath('data.min_area_applied', true)
            ->assertJsonPath('data.line_total_cents', 3000);
    }

    public function test_validation_and_prohibited_fields(): void
    {
        $vinyl = $this->id('VIN-BR-122-BR');
        $url = '/api/v1/products/vinil-adesivo-branco-122m/price-preview';
        $this->postJson($url, ['variant_id' => $vinyl, 'quantity' => 5, 'price_cents' => 1])->assertStatus(422)->assertJsonValidationErrors('price_cents');
        $this->postJson($url, ['variant_id' => $vinyl, 'quantity' => 5, 'unit_price_cents' => null])->assertStatus(422)->assertJsonValidationErrors('unit_price_cents');
        $this->postJson($url, ['variant_id' => $vinyl, 'quantity' => '1e3'])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson($url, ['variant_id' => $vinyl, 'quantity' => 5, 'width_m' => 1])->assertStatus(422)->assertJsonValidationErrors('width_m');
        $this->postJson($url, ['variant_id' => $this->id('ILH-0-LAT'), 'quantity' => 5])->assertStatus(422)->assertJsonValidationErrors('variant_id');
        $this->postJson('/api/v1/products/nope/price-preview', ['variant_id' => $vinyl, 'quantity' => 5])->assertNotFound();
        $this->postJson('/api/v1/products/lona-frontlight-440g/price-preview', ['variant_id' => $this->id('LON-FL-440-SM'), 'width_m' => 4, 'height_m' => 2, 'pieces' => 1])
            ->assertStatus(422)->assertJsonPath('errors.width_m.0', 'Largura máxima: 3,20 m. Tente inverter largura e altura.');
        $this->postJson('/api/v1/products/lona-frontlight-440g/price-preview', ['variant_id' => $this->id('LON-FL-440-SM'), 'width_m' => '1.205', 'height_m' => 2, 'pieces' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('width_m');
    }

    public function test_insufficient_stock_is_not_an_error(): void
    {
        $this->postJson('/api/v1/products/adesivo-jateado-122m/price-preview', ['variant_id' => $this->id('ADJ-122'), 'quantity' => 10])
            ->assertOk()->assertJsonPath('data.stock', ['sufficient' => false, 'available_quantity' => 8]);
    }
}
