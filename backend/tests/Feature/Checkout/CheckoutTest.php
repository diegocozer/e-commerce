<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Modules\Cart\Contracts\OrderShippingRequestSource;
use App\Modules\Cart\Models\Cart;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\DTOs\GatewayPayment;
use App\Modules\Payments\DTOs\GatewayRefund;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Exceptions\PaymentGatewayUnavailable;
use App\Modules\Payments\Gateways\SandboxGateway;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Shared\Domain\Money;
use App\Shared\PostalCode\PostalCodeInfo;
use App\Shared\PostalCode\PostalCodeLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cart\CartTestHelpers;
use Tests\TestCase;

/**
 * POST /checkout and /checkout/preview (API.md §3.E) — integration with the real
 * Catalog, Pricing, Inventory, Shipping, Orders and Payments (sandbox) modules.
 * Flow of API.md §4.6: vinyl 5 m (R$ 79,50) + own delivery "2:2" (R$ 20,00) = R$ 99,50.
 */
final class CheckoutTest extends TestCase
{
    use CartTestHelpers;
    use RefreshDatabase;

    protected bool $seed = true;

    private Customer $customer;

    private string $addressUuid;

    private string $quoteId;

    private string $optionId = '2:2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSeeded();
        $this->customer = $this->maria();
        $this->addressUuid = $this->addressOf($this->customer)->uuid; // seeded "Casa", CEP 89012-000
        $this->actingAs($this->customer, 'customer');
    }

    private function prepareCart(float|int $meters = 5): void
    {
        $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => $meters])->assertSuccessful();
        $this->quote();
    }

    private function quote(): void
    {
        $quote = $this->postJson('/api/v1/cart/shipping-quote', ['address_uuid' => $this->addressUuid])->assertOk()->json('data');
        $this->quoteId = $quote['quote_id'];
        $own = collect($quote['options'])->firstWhere('method_type', 'own_delivery');
        self::assertNotNull($own, 'own delivery must be offered for the seeded Blumenau address');
        self::assertSame(2000, $own['price_cents']);
        $this->optionId = $own['option_id'];
    }

    /** @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return [
            'address_uuid' => $this->addressUuid,
            'shipping_quote_id' => $this->quoteId,
            'shipping_option_id' => $this->optionId,
            'payment_method' => 'pix',
            'expected_total_cents' => 9950,
            'notes' => 'Entregar após 14h',
            'accept_terms' => true,
            ...$overrides,
        ];
    }

    private function checkout(array $body, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/checkout', $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** Services holding the lookup were resolved during setUp/prepareCart: rebuild them. */
    private function forgetShippingSingletons(): void
    {
        foreach (array_keys($this->app->getBindings()) as $abstract) {
            if (str_starts_with($abstract, 'App\\Modules\\Shipping\\') || str_starts_with($abstract, 'App\\Modules\\Checkout\\')
                || str_starts_with($abstract, 'App\\Modules\\Cart\\')) {
                $this->app->forgetInstance($abstract);
            }
        }
    }

    private function failGateway(bool $fail): void
    {
        $manager = $this->app->make(PaymentGatewayManager::class);
        $manager->forgetDrivers();
        $real = new SandboxGateway($this->app->make('cache.store'));
        $manager->extend('sandbox', fn (): PaymentGatewayInterface => $fail ? new class($real) implements PaymentGatewayInterface
        {
            public function __construct(private readonly PaymentGatewayInterface $real) {}

            public function createPayment(PaymentRequest $request, string $idempotencyKey): GatewayPayment
            {
                throw new PaymentGatewayUnavailable;
            }

            public function getPayment(string $externalId): GatewayPayment
            {
                return $this->real->getPayment($externalId);
            }

            public function refund(string $externalId, ?Money $amount, string $idempotencyKey): GatewayRefund
            {
                return $this->real->refund($externalId, $amount, $idempotencyKey);
            }

            public function supports(PaymentMethod $method): bool
            {
                return $this->real->supports($method);
            }
        } : $real);
    }

    public function test_preview_returns_the_summary_without_side_effects(): void
    {
        $this->prepareCart();

        $this->postJson('/api/v1/checkout/preview', ['address_uuid' => $this->addressUuid, 'shipping_quote_id' => $this->quoteId,
            'shipping_option_id' => $this->optionId, 'payment_method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.totals', ['subtotal_cents' => 7950, 'discount_cents' => 0, 'shipping_cents' => 2000, 'shipping_discount_cents' => 0, 'total_cents' => 9950])
            ->assertJsonPath('data.can_place_order', true)
            ->assertJsonPath('data.blocking', [])
            ->assertJsonPath('data.shipping_option.option_id', $this->optionId)
            ->assertJsonPath('data.address.uuid', $this->addressUuid)
            ->assertJsonPath('data.billing.name', 'Maria da Silva')
            ->assertJsonPath('data.payment_expires_in_minutes', 30);

        $this->postJson('/api/v1/checkout/preview', ['address_uuid' => $this->addressUuid, 'payment_method' => 'pix'])
            ->assertOk()->assertJsonPath('data.can_place_order', false)->assertJsonPath('data.blocking.0.code', 'shipping_required')
            ->assertJsonPath('data.totals.shipping_cents', null);

        self::assertSame(0, Order::query()->count());
    }

    public function test_happy_path_vinyl_5_m_creates_order_reserves_stock_and_returns_pix(): void
    {
        $this->prepareCart();
        $vinyl = $this->variantId('VIN-BR-122-BR');
        $reservedBefore = (string) DB::table('inventory')->where('variant_id', $vinyl)->value('reserved');

        $response = $this->checkout($this->body())->assertCreated();

        $response->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.order.status', 'pending_payment')
            ->assertJsonPath('data.order.payment_status', 'pending')
            ->assertJsonPath('data.order.total_cents', 9950)
            ->assertJsonPath('data.order.totals', ['subtotal_cents' => 7950, 'discount_cents' => 0, 'shipping_cents' => 2000, 'shipping_discount_cents' => 0, 'total_cents' => 9950])
            ->assertJsonPath('data.order.items.0.sku', 'VIN-BR-122-BR')
            ->assertJsonPath('data.order.items.0.unit_price_cents', 1590)
            ->assertJsonPath('data.order.items.0.subtotal_cents', 7950)
            ->assertJsonPath('data.order.shipping.method_type', 'own_delivery')
            ->assertJsonPath('data.order.shipping.address.postal_code', '89012000')
            ->assertJsonPath('data.order.notes', 'Entregar após 14h')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.amount_cents', 9950);
        self::assertNotEmpty($response->json('data.payment.pix.copy_paste'));
        self::assertMatchesRegularExpression('/^CV-\d{6}$/', (string) $response->json('data.order.number'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));

        self::assertSame(1, Order::query()->count());
        self::assertNotNull(Cart::query()->where('customer_id', $this->customer->id)->value('converted_at'));
        self::assertEquals((float) $reservedBefore + 5, (float) DB::table('inventory')->where('variant_id', $vinyl)->value('reserved'));

        // the cart is now empty for the customer
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_idempotent_replay_and_conflict(): void
    {
        $this->prepareCart();
        $key = (string) Str::uuid();

        $first = $this->checkout($this->body(), $key)->assertCreated();
        $replay = $this->checkout($this->body(), $key)->assertOk()->assertJsonPath('data.replayed', true);
        self::assertSame($first->json('data.order.uuid'), $replay->json('data.order.uuid'));
        self::assertSame($first->json('data.payment.uuid'), $replay->json('data.payment.uuid'));
        self::assertSame(1, Order::query()->count());
        self::assertSame(1, Payment::query()->count());

        $this->checkout($this->body(['shipping_option_id' => '1:pickup']), $key)
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict')
            ->assertJsonPath('order.uuid', $first->json('data.order.uuid'));
    }

    public function test_idempotency_key_is_required(): void
    {
        $this->prepareCart();
        $this->postJson('/api/v1/checkout', $this->body())->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/v1/checkout', $this->body(), ['Idempotency-Key' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_requires_customer_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('customer')->logout();
        $this->postJson('/api/v1/checkout', [], ['Idempotency-Key' => (string) Str::uuid()])->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/checkout/preview', [])->assertUnauthorized();
    }

    public function test_expected_total_mismatch_returns_409_price_changed_with_new_summary(): void
    {
        $this->prepareCart();
        ProductVariant::query()->whereKey($this->variantId('VIN-BR-122-BR'))->update(['price_cents' => 1650]);

        $this->checkout($this->body())
            ->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')
            ->assertJsonPath('summary.totals.subtotal_cents', 8250)
            ->assertJsonPath('summary.totals.total_cents', 10250)
            ->assertJsonPath('summary.items.0.warnings.0.code', 'price_changed');
        self::assertSame(0, Order::query()->count());

        $this->checkout($this->body(['expected_total_cents' => 10250]))->assertCreated()->assertJsonPath('data.order.total_cents', 10250);
    }

    public function test_price_and_discount_manipulation_is_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->prepareCart();
        foreach ([
            'total_cents' => 1, 'unit_price_cents' => 1, 'price' => 1, 'discount_cents' => 9000, 'discount' => 50,
            'shipping_cents' => 0, 'shipping_price' => 0, 'customer_id' => 999, 'status' => 'paid', 'items' => [],
            'coupon_code' => 'VALE500', 'payment_status' => 'approved', 'shipping_price_cents' => null,
        ] as $field => $value) {
            $this->checkout($this->body([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        // a low expected total is only compared, never used
        $this->checkout($this->body(['expected_total_cents' => 1]))->assertStatus(409)->assertJsonPath('code', 'price_changed');
        $this->checkout($this->body(['payment_method' => 'credit_card']))->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->checkout($this->body(['accept_terms' => false]))->assertUnprocessable()->assertJsonValidationErrors('accept_terms');
        self::assertSame(0, Order::query()->count());
    }

    public function test_shipping_manipulation_fake_option_and_expired_quote(): void
    {
        $this->prepareCart();

        $this->checkout($this->body(['shipping_option_id' => '2:999']))
            ->assertStatus(409)->assertJsonPath('code', 'shipping_option_invalid')->assertJsonStructure(['shipping_quote' => ['quote_id', 'options']]);
        $this->checkout($this->body(['shipping_quote_id' => (string) Str::uuid()]))
            ->assertStatus(409)->assertJsonPath('code', 'shipping_quote_invalid');

        // expired quote with a changed price → 409 (silent acceptance only when the price is the same)
        $this->travel(31)->minutes();
        DB::table('shipping_rules')->where('id', (int) explode(':', $this->optionId)[1])->update(['price_cents' => 2500]);
        $response = $this->checkout($this->body());
        $response->assertStatus(409);
        self::assertStringStartsWith('shipping_', (string) $response->json('code'));
        self::assertSame(0, Order::query()->count());
    }

    public function test_coupon_discount_is_server_side_and_invalid_coupon_at_checkout_is_409(): void
    {
        $this->prepareCart(20); // 20 × 1490 = 29800
        $this->putJson('/api/v1/cart/coupon', ['code' => 'DESC20'])->assertOk()->assertJsonPath('data.coupon.valid', true);
        $this->quote();

        $this->checkout($this->body(['expected_total_cents' => 29800 - 2000 + 2000]))
            ->assertCreated()
            ->assertJsonPath('data.order.totals.discount_cents', 2000)
            ->assertJsonPath('data.order.coupon_code', 'DESC20');
        self::assertSame(1, DB::table('coupon_redemptions')->count());
    }

    public function test_coupon_that_became_invalid_returns_409_coupon_invalid(): void
    {
        $this->prepareCart(20);
        $this->putJson('/api/v1/cart/coupon', ['code' => 'DESC20'])->assertOk();
        $this->quote();
        DB::table('coupons')->where('code', 'DESC20')->update(['is_active' => false]);

        $this->checkout($this->body(['expected_total_cents' => 29800]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'coupon_invalid')
            ->assertJsonPath('coupon.code', 'DESC20')
            ->assertJsonPath('summary.totals.discount_cents', 0);
        self::assertSame(0, Order::query()->count());
    }

    public function test_address_of_another_customer_is_rejected(): void
    {
        $this->prepareCart();
        $other = $this->addressOf($this->joao())->uuid;

        $this->checkout($this->body(['address_uuid' => $other]))->assertUnprocessable()->assertJsonPath('errors.address_uuid.0', 'Endereço inválido.');
        $this->postJson('/api/v1/checkout/preview', ['address_uuid' => $other, 'payment_method' => 'pix'])->assertUnprocessable();
        self::assertSame(0, Order::query()->count());
    }

    public function test_insufficient_stock_returns_409_and_nothing_is_created(): void
    {
        $this->prepareCart();
        DB::table('inventory')->where('variant_id', $this->variantId('VIN-BR-122-BR'))->update(['on_hand' => 3, 'reserved' => 0]);

        $this->checkout($this->body())
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('items.0.available_quantity', 3)
            ->assertJsonPath('items.0.requested_quantity', 5);
        self::assertSame(0, Order::query()->count());
        self::assertNull(Cart::query()->where('customer_id', $this->customer->id)->value('converted_at'));
    }

    public function test_empty_cart_and_invalid_items(): void
    {
        $this->quoteId = (string) Str::uuid();
        $this->checkout($this->body())->assertStatus(409)->assertJsonPath('code', 'cart_empty');

        $this->prepareCart();
        ProductVariant::query()->whereKey($this->variantId('VIN-BR-122-BR'))->update(['is_active' => false]);
        $this->checkout($this->body())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cart_invalid')
            ->assertJsonPath('items.0.reason', 'unavailable')
            ->assertJsonValidationErrors('cart');
    }

    public function test_gateway_failure_returns_503_and_retry_with_same_key_creates_the_pix(): void
    {
        $this->prepareCart();
        $key = (string) Str::uuid();
        $this->failGateway(true);

        $failed = $this->checkout($this->body(), $key)
            ->assertStatus(503)
            ->assertJsonPath('code', 'payment_gateway_unavailable');
        $order = Order::query()->firstOrFail();
        self::assertSame($order->uuid, $failed->json('order.uuid'));
        self::assertSame('pending_payment', $order->status->value);
        self::assertNull(Payment::query()->value('external_id'));

        $this->failGateway(false);
        $retry = $this->checkout($this->body(), $key)->assertOk()->assertJsonPath('data.replayed', true);
        self::assertSame($order->uuid, $retry->json('data.order.uuid'));
        self::assertNotEmpty($retry->json('data.payment.pix.copy_paste'));
        self::assertSame(1, Order::query()->count());
    }

    public function test_no_postal_code_lookup_happens_inside_the_checkout_transaction(): void
    {
        $this->prepareCart();
        $real = $this->app->make(PostalCodeLookup::class);
        $levels = new \ArrayObject;
        $this->app->instance(PostalCodeLookup::class, new class($real, $levels) implements PostalCodeLookup
        {
            public function __construct(private readonly PostalCodeLookup $real, private readonly \ArrayObject $levels) {}

            public function lookup(string $postalCode): PostalCodeInfo
            {
                $this->levels->append(DB::transactionLevel());

                return $this->real->lookup($postalCode);
            }
        });
        $this->forgetShippingSingletons();
        $baseline = DB::transactionLevel(); // RefreshDatabase wraps the test in a transaction

        $this->checkout($this->body())->assertCreated();

        self::assertNotEmpty($levels->getArrayCopy(), 'the checkout must resolve the destination (pre-check)');
        self::assertSame([$baseline], array_values(array_unique($levels->getArrayCopy())));
    }

    public function test_order_shipping_request_source_for_the_admin_simulator(): void
    {
        $this->prepareCart();
        $order = $this->checkout($this->body())->assertCreated()->json('data.order');
        $id = (int) Order::query()->where('uuid', $order['uuid'])->value('id');

        $source = $this->app->make(OrderShippingRequestSource::class);
        $result = $source->forOrder($id);

        self::assertSame('89012000', $result['postal_code']);
        self::assertSame(7950, $result['subtotal_cents']);
        self::assertCount(1, $result['lines']);
        self::assertSame(5000, $result['lines'][0]->billable->milli());
        self::assertNull($source->forOrder(999999));
    }

    public function test_checkout_rate_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/checkout', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/checkout', [])->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    }

    public function test_too_many_pending_orders(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        for ($i = 0; $i < 3; $i++) {
            $this->prepareCart(1);
            $this->checkout($this->body(['expected_total_cents' => 1590 + 2000]))->assertCreated();
        }
        $this->prepareCart(1);
        $this->checkout($this->body(['expected_total_cents' => 3590]))
            ->assertStatus(409)->assertJsonPath('code', 'too_many_pending_orders')->assertJsonCount(3, 'pending_orders');
    }
}
