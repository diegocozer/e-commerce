<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Modules\Cart\Models\Cart;
use App\Modules\Customers\Models\Customer;
use App\Modules\Shipping\Contracts\ShippingQuoteService;
use App\Modules\Shipping\Exceptions\ShippingConflict;
use App\Modules\Shipping\Models\ShippingQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Shipping\Concerns\BuildsShippingFixture;
use Tests\TestCase;

/** SHIPPING.md §6.5/§7 — persistence and checkout validation (T27–T33, T50, T53, T55). */
final class ShippingQuoteServiceTest extends TestCase
{
    use BuildsShippingFixture;
    use RefreshDatabase;

    private Cart $cart;

    private Customer $customer;

    /** @var list<array<string, int|null>> */
    private array $items = [['variant_id' => 1, 'quantity_milli' => 1000, 'width_mm' => null, 'height_mm' => null, 'pieces' => null]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedShippingFixture();
        $this->customer = Customer::factory()->create();
        $this->cart = Cart::factory()->create(['customer_id' => $this->customer->id]);
    }

    private function service(): ShippingQuoteService
    {
        return app(ShippingQuoteService::class);
    }

    private function request(string $cep = self::BLUMENAU, int $weight = 7200, int $subtotal = 32000, bool $coupon = false)
    {
        return $this->shippingRequest($cep, $weight, $subtotal, $coupon, cartId: $this->cart->id, customerId: $this->customer->id);
    }

    private function ownOptionId(): string
    {
        return $this->methods['own']->id.':'.$this->rules[100]->id;
    }

    private function validate(string $quoteId, string $optionId, $request = null, ?array $items = null, ?int $customerId = null)
    {
        return $this->service()->validateSelectionForCheckout($quoteId, $optionId, $request ?? $this->request(), $items ?? $this->items, $this->cart->id, $customerId ?? $this->customer->id);
    }

    private function expectConflict(string $code, callable $fn): ShippingConflict
    {
        try {
            $fn();
        } catch (ShippingConflict $e) {
            self::assertSame($code, $e->errorCode());
            self::assertSame(409, $e->httpStatus());
            self::assertNotNull($e->newQuote->quoteId);
            self::assertArrayHasKey('shipping_quote', $e->details());

            return $e;
        }
        self::fail("Expected ShippingConflict {$code}");
    }

    public function test_quote_is_persisted_with_ttl_and_public_shape(): void
    {
        $this->travelTo('2026-09-24T15:00:00Z');
        $quote = $this->service()->quoteAndStore($this->request(), $this->items);

        self::assertNotNull($quote->quoteId);
        self::assertSame('2026-09-24T15:30:00Z', $quote->toArray()['expires_at']);
        $row = ShippingQuote::query()->where('uuid', $quote->quoteId)->sole();
        self::assertSame($this->cart->id, $row->cart_id);
        self::assertSame(7200, $row->total_weight_grams);
        self::assertCount(4, $row->options);
        self::assertSame($this->rules[100]->id, collect($row->options)->firstWhere('id', $this->ownOptionId())['rule_id']);

        $public = $quote->toArray();
        self::assertSame(['postal_code' => '89010100', 'city' => 'Blumenau', 'state' => 'SC'], $public['destination']);
        self::assertArrayNotHasKey('rule_id', $public['options'][0]);
        self::assertNull($public['notice']);
        self::assertNull($public['message']);
    }

    public function test_identical_valid_quote_is_reused_until_config_changes(): void
    {
        $a = $this->service()->quoteAndStore($this->request(), $this->items);
        self::assertSame($a->quoteId, $this->service()->quoteAndStore($this->request(), $this->items)->quoteId);

        $this->rules[100]->update(['price_cents' => 2100]);
        $c = $this->service()->quoteAndStore($this->request(), $this->items);
        self::assertNotSame($a->quoteId, $c->quoteId);
        self::assertSame(2100, $c->option($this->ownOptionId())?->priceCents);
    }

    public function test_t50_different_input_gives_new_quote_with_same_option_ids(): void
    {
        $a = $this->service()->quoteAndStore($this->request(), $this->items);
        $b = $this->service()->quoteAndStore($this->request(subtotal: 32001), $this->items);

        self::assertNotSame($a->quoteId, $b->quoteId);
        self::assertSame(array_map(fn ($o) => $o->optionId, $a->options), array_map(fn ($o) => $o->optionId, $b->options));
    }

    public function test_valid_quote_passes_checkout(): void
    {
        $quote = $this->service()->quoteAndStore($this->request(), $this->items);
        $selection = $this->validate((string) $quote->quoteId, $this->ownOptionId());

        self::assertSame($quote->quoteId, $selection->quoteId);
        self::assertSame(2000, $selection->option->priceCents);
        self::assertSame(2000, $this->service()->validateForCheckout((string) $quote->quoteId, $this->ownOptionId(), $this->request(), $this->items, $this->cart->id, $this->customer->id)->priceCents);
    }

    public function test_t27_expired_quote_same_price_is_accepted_with_new_quote(): void
    {
        $quote = $this->service()->quoteAndStore($this->request(), $this->items);
        $this->travel(31)->minutes();

        $selection = $this->validate((string) $quote->quoteId, $this->ownOptionId());
        self::assertNotSame($quote->quoteId, $selection->quoteId);
        self::assertSame(2000, $selection->option->priceCents);
    }

    public function test_t28_expired_quote_with_price_change_is_409(): void
    {
        $quote = $this->service()->quoteAndStore($this->request(), $this->items);
        $this->rules[100]->update(['price_cents' => 2500]);
        $this->travel(31)->minutes();

        $e = $this->expectConflict('shipping_quote_expired', fn () => $this->validate((string) $quote->quoteId, $this->ownOptionId()));
        self::assertSame(2500, $e->newQuote->option($this->ownOptionId())?->priceCents);
    }

    public function test_t29_hash_mismatch(): void
    {
        $quote = $this->service()->quoteAndStore($this->request(), $this->items);
        $changedItems = [['variant_id' => 1, 'quantity_milli' => 2000, 'width_mm' => null, 'height_mm' => null, 'pieces' => null]];

        // same price ⇒ silently accepted with a new quote
        $selection = $this->validate((string) $quote->quoteId, $this->ownOptionId(), $this->request(weight: 9000), $changedItems);
        self::assertNotSame($quote->quoteId, $selection->quoteId);

        // table price changes with the weight ⇒ 409
        $regional = $this->methods['regional']->id.':'.$this->rules[201]->id;
        $this->expectConflict('shipping_quote_changed', fn () => $this->validate((string) $quote->quoteId, $regional, $this->request(weight: 12000), $changedItems));
    }

    public function test_t30_postal_code_changed_is_always_409(): void
    {
        $quote = $this->service()->quoteAndStore($this->request(), $this->items);

        $e = $this->expectConflict('shipping_postal_code_changed', fn () => $this->validate((string) $quote->quoteId, $this->ownOptionId(), $this->request(self::GASPAR)));
        self::assertSame('89110000', $e->newQuote->destination->postalCode);
    }

    public function test_t31_quote_of_another_customer_is_invalid_and_not_leaked(): void
    {
        $other = Customer::factory()->create();
        $otherCart = Cart::factory()->create(['customer_id' => $other->id]);
        $foreign = $this->service()->quoteAndStore($this->shippingRequest(cartId: $otherCart->id, customerId: $other->id), $this->items);

        $e = $this->expectConflict('shipping_quote_invalid', fn () => $this->validate((string) $foreign->quoteId, $this->ownOptionId()));
        self::assertNotSame($foreign->quoteId, $e->newQuote->quoteId);
        self::assertSame($this->cart->id, $e->newQuote->cartId);
    }

    public function test_unknown_quote_and_option(): void
    {
        $this->expectConflict('shipping_quote_invalid', fn () => $this->validate('0b8f7c2e-6a61-4f3a-9d0e-3c9a4a1f2b10', $this->ownOptionId()));
        $this->expectConflict('shipping_quote_invalid', fn () => $this->validate('not-a-uuid', $this->ownOptionId()));

        $quote = $this->service()->quoteAndStore($this->request(), $this->items);
        $this->expectConflict('shipping_option_invalid', fn () => $this->validate((string) $quote->quoteId, '99:99'));
    }

    public function test_t33_rule_changed_with_valid_quote_is_price_changed(): void
    {
        $quote = $this->service()->quoteAndStore($this->request(), $this->items);
        $this->rules[100]->update(['price_cents' => 2200]);

        $e = $this->expectConflict('shipping_price_changed', fn () => $this->validate((string) $quote->quoteId, $this->ownOptionId()));
        self::assertSame(2200, $e->newQuote->option($this->ownOptionId())?->priceCents);
        self::assertSame(ShippingConflictMessage::PRICE_CHANGED, $e->getMessage());
    }

    public function test_option_disappeared_is_unavailable(): void
    {
        $quote = $this->service()->quoteAndStore($this->request(), $this->items);
        $this->methods['own']->update(['is_active' => false]);

        $this->expectConflict('shipping_option_unavailable', fn () => $this->validate((string) $quote->quoteId, $this->ownOptionId()));
    }

    public function test_carrier_option_uses_persisted_price_when_valid(): void
    {
        $method = $this->carrierMethod(['mode' => 'ok']);
        $quote = $this->service()->quoteAndStore($this->request(), $this->items);
        $method->carrier->update(['settings' => ['mode' => 'error']]);

        self::assertSame(3990, $this->validate((string) $quote->quoteId, $method->id.':EXP')->option->priceCents);
    }

    public function test_quote_with_zero_options_is_persisted_with_message(): void
    {
        $this->methods['pickup']->update(['is_active' => false]);
        $quote = $this->service()->quoteAndStore($this->request(self::SAO_PAULO), $this->items);

        self::assertSame([], $quote->toArray()['options']);
        self::assertSame('Não há opções de entrega para este CEP.', $quote->toArray()['message']);
        self::assertSame(1, ShippingQuote::query()->count());
    }

    public function test_pickup_only_notice(): void
    {
        $request = $this->shippingRequest(lines: [$this->unitLine(100, pickupOnly: true)], cartId: $this->cart->id);
        $data = $this->service()->estimate($request)->toArray();

        self::assertSame('pickup_only_items', $data['notice']);
        self::assertNull($data['quote_id']);
        self::assertCount(1, $data['options']);
    }

    public function test_t53_logs_only_cep_prefix_and_t05_no_options_warning(): void
    {
        $logs = $this->captureLogs();
        $this->service()->quoteAndStore($this->request(), $this->items);
        $this->methods['pickup']->update(['is_active' => false]);
        $this->service()->estimate($this->shippingRequest(self::SAO_PAULO));

        $evaluated = $this->logsNamed($logs, 'shipping.quote.evaluated');
        self::assertCount(2, $evaluated);
        self::assertSame('89010', $evaluated[0]['context']['cep_prefix']);
        self::assertStringNotContainsString('89010100', json_encode($logs->getArrayCopy()));
        self::assertStringNotContainsString('01310100', json_encode($logs->getArrayCopy()));
        self::assertCount(1, $this->logsNamed($logs, 'shipping.quote.no_options'));
    }

    public function test_t04_no_options_warning_not_logged_when_pickup_exists(): void
    {
        $logs = $this->captureLogs();
        $this->service()->estimate($this->shippingRequest(self::SAO_PAULO));

        self::assertSame([], $this->logsNamed($logs, 'shipping.quote.no_options'));
    }

    public function test_t55_prune_removes_quotes_expired_more_than_7_days_ago(): void
    {
        $old = $this->service()->quoteAndStore($this->request(), $this->items);
        $this->travel(8)->days();
        $recent = $this->service()->quoteAndStore($this->request(), $this->items);

        $this->artisan('shipping:prune-quotes')->assertSuccessful();

        self::assertNull(ShippingQuote::query()->where('uuid', $old->quoteId)->first());
        self::assertNotNull(ShippingQuote::query()->where('uuid', $recent->quoteId)->first());
    }
}

/** @internal */
final class ShippingConflictMessage
{
    public const string PRICE_CHANGED = 'O valor do frete foi atualizado. Escolha novamente a forma de entrega.';
}
