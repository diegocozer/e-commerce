<?php

declare(strict_types=1);

namespace Tests\Feature\Orders\Support;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Orders\Contracts\OrderPlacement;
use App\Modules\Orders\DTOs\AddressSnapshot;
use App\Modules\Orders\DTOs\CustomerSnapshot;
use App\Modules\Orders\DTOs\OrderLineData;
use App\Modules\Orders\DTOs\PlaceOrderData;
use App\Modules\Orders\DTOs\ShippingSnapshot;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentService;
use App\Modules\Payments\DTOs\PayerData;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Modules\Pricing\DTOs\CouponContext;
use App\Modules\Pricing\DTOs\CouponEvaluation;
use App\Modules\Pricing\Enums\PriceSource;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Base of the Orders/Payments/Notifications tests (B-D1). Uses the REAL
 * InventoryService/CouponService (B-B) and a controllable fake gateway
 * registered as the `sandbox` driver.
 */
abstract class OrdersTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Referer' => 'http://localhost/', 'Accept' => 'application/json']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->gateway = new FakePaymentGateway;
        $manager = $this->app->make(PaymentGatewayManager::class);
        $manager->forgetDrivers();
        $gateway = $this->gateway;
        $manager->extend('sandbox', static fn (): FakePaymentGateway => $gateway);
        config(['payments.driver' => 'sandbox', 'payments.drivers.sandbox.webhook_secret' => 'test-secret']);
    }

    protected function variant(string $onHand = '10'): ProductVariant
    {
        return ProductVariant::factory()->withStock($onHand)->create();
    }

    /**
     * Places an order through OrderPlacement + PaymentService (as Checkout does)
     * and initiates the PIX outside the transaction.
     *
     * @param  list<array{0: ProductVariant, 1: string}>  $lines  [variant, quantity]
     */
    protected function placeOrder(?Customer $customer = null, array $lines = [], bool $initiate = true, string $methodType = 'own_delivery', ?string $couponCode = null, int $discountCents = 0): Order
    {
        $customer ??= Customer::factory()->create();
        $lines = $lines !== [] ? $lines : [[$this->variant(), '2']];

        $orderLines = [];
        foreach ($lines as [$variant, $qty]) {
            $q = Quantity::fromString($qty);
            $orderLines[] = new OrderLineData(
                variantId: $variant->id, productId: $variant->product_id, productName: 'Vinil Adesivo', variantName: 'Branco',
                sku: $variant->sku, saleUnit: SaleUnit::Unit, quantity: $q, widthMm: null, heightMm: null, pieces: null,
                billableQuantity: $q, stockQuantity: $q, baseUnitPrice: Money::ofCents(1000), unitPrice: Money::ofCents(1000),
                priceSource: PriceSource::Base, subtotal: Money::ofCents(1000)->multiplyByQuantity($q), weightGrams: 100,
            );
        }
        $subtotal = Money::sum(array_map(static fn (OrderLineData $l): Money => $l->subtotal, $orderLines));
        $shipping = $methodType === 'pickup' ? Money::zero() : Money::ofCents(2000);
        $discount = Money::ofCents($discountCents);
        $coupon = $couponCode === null ? null : new CouponEvaluation(true, null, null, $discount, false, null, $couponCode);

        $data = new PlaceOrderData(
            customerId: $customer->id,
            idempotencyKey: (string) Str::uuid(),
            fingerprint: hash('sha256', Str::random()),
            customer: new CustomerSnapshot($customer->type, $customer->name, $customer->email, $customer->cpf ?? '52998224725'),
            shippingAddress: new AddressSnapshot(null, $customer->name, null, '89010001', 'Rua XV de Novembro', '100', null, 'Centro', 'Blumenau', 'SC', '4202404'),
            lines: $orderLines,
            shipping: new ShippingSnapshot($methodType === 'pickup' ? '1:pickup' : '2:100', $methodType === 'pickup' ? 'Retirada na loja' : 'Entrega própria', $methodType, deliveryDaysMin: 1, deliveryDaysMax: 2),
            coupon: $coupon,
            subtotal: $subtotal,
            discount: $discount,
            shippingTotal: $shipping,
            total: $subtotal->subtract($discount)->add($shipping),
            paymentMethod: PaymentMethod::Pix,
            expiresAt: CarbonImmutable::now()->addMinutes(30),
            notes: null,
            couponContext: $coupon === null ? null : new CouponContext($customer->id, $subtotal, [], $shipping),
        );

        $paymentId = DB::transaction(function () use ($data, $customer): int {
            $order = app(OrderPlacement::class)->place($data);

            return app(PaymentService::class)->createPending(new PaymentRequest(
                $order->id, $order->number, PaymentMethod::Pix, $order->total,
                new PayerData($customer->name, $customer->email, $customer->cpf), $data->expiresAt,
            ))->id;
        });
        if ($initiate) {
            app(PaymentService::class)->initiate($paymentId);
        }

        return Order::query()->where('idempotency_key', $data->idempotencyKey)->firstOrFail();
    }

    protected function payment(Order $order): Payment
    {
        return Payment::query()->where('order_id', $order->id)->orderByDesc('id')->firstOrFail();
    }

    /** @return array{on_hand: string, reserved: string} */
    protected function stock(ProductVariant $variant): array
    {
        $row = Inventory::query()->where('variant_id', $variant->id)->firstOrFail();

        return ['on_hand' => $row->on_hand->toDecimalString(), 'reserved' => $row->reserved->toDecimalString()];
    }

    /** @param list<AdminPermission|string> $permissions */
    protected function actingAsAdmin(array $permissions = [], ?AdminRole $role = null): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = AdminUser::factory()->create();
        if ($role !== null) {
            $admin->assignRole($role->value);
        }
        if ($permissions !== []) {
            $custom = Role::findOrCreate('test-role-'.$admin->id, AdminPermission::GUARD);
            $custom->syncPermissions(array_map(static fn (AdminPermission|string $p): string => $p instanceof AdminPermission ? $p->value : $p, $permissions));
            $admin->assignRole($custom);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // New actor = new session (Sanctum AuthenticateSession keeps the previous password hash).
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($admin->refresh(), 'admin');

        return $admin;
    }

    /** Signed webhook (sandbox scheme) for a gateway payment id. @return array<string, string> */
    protected function signedHeaders(string $dataId, ?int $ts = null, string $secret = 'test-secret'): array
    {
        $ts ??= CarbonImmutable::now()->getTimestamp();
        $requestId = (string) Str::uuid();
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";

        return ['x-signature' => "ts={$ts},v1=".hash_hmac('sha256', $manifest, $secret), 'x-request-id' => $requestId];
    }

    protected function sendWebhook(string $dataId, string $eventId = 'evt_1', ?array $headers = null): TestResponse
    {
        return $this->withHeaders($headers ?? $this->signedHeaders(strtolower($dataId)))
            ->postJson('/api/v1/webhooks/sandbox', ['id' => $eventId, 'type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $dataId]]);
    }
}
