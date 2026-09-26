<?php
namespace Tests\Feature\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class DbgTest extends TestCase {
    use CartTestHelpers; use RefreshDatabase; protected bool $seed = true;
    public function test_x(): void {
        $r = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5]);
        fwrite(STDERR, json_encode([$r->json('data.total_weight_grams'), $r->json('data.items.0.weight_grams')]));
        $q = $this->postJson('/api/v1/cart/shipping-quote', ['postal_code' => '89010-000'], ['X-Cart-Token' => $r->json('data.token')]);
        fwrite(STDERR, json_encode($q->json()));
        $this->assertTrue(true);
    }
}
