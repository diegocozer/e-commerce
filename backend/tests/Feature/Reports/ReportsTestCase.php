<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

abstract class ReportsTestCase extends TestCase
{
    use RefreshDatabase;

    protected const array PERMISSIONS = [
        'dashboard.view', 'orders.view', 'inventory.view',
        'reports.view', 'reports.sales', 'reports.inventory', 'reports.export',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'admin');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function actingAsAdminWith(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo($permissions);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    /** Local (America/Sao_Paulo) date-time → UTC instant. */
    protected static function sp(string $localDateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($localDateTime, 'America/Sao_Paulo')->utc();
    }

    /**
     * Paid order with consistent totals.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function paidOrder(string $paidAtLocal, int $subtotal, array $attributes = [], ?Customer $customer = null): Order
    {
        $paidAt = self::sp($paidAtLocal);
        $discount = $attributes['discount_cents'] ?? 0;
        $shipping = $attributes['shipping_cents'] ?? 2000;
        $shippingDiscount = $attributes['shipping_discount_cents'] ?? 0;

        return Order::factory()->paid()->create([
            'customer_id' => $customer?->id ?? Customer::factory(),
            'placed_at' => $paidAt->subMinutes(5),
            'paid_at' => $paidAt,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'shipping_cents' => $shipping,
            'shipping_discount_cents' => $shippingDiscount,
            'total_cents' => $subtotal - $discount + $shipping - $shippingDiscount,
            ...$attributes,
        ]);
    }

    protected function refund(Order $order, string $refundedAtLocal): Order
    {
        Order::query()->whereKey($order->id)->update([
            'status' => 'cancelled', 'payment_status' => 'refunded',
            'cancelled_at' => self::sp($refundedAtLocal), 'cancel_reason_code' => 'admin',
            'refunded_at' => self::sp($refundedAtLocal),
        ]);

        return $order->refresh();
    }

    /** @param array<string, mixed> $attributes */
    protected function item(Order $order, ProductVariant $variant, string $quantity, int $total, array $attributes = []): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'quantity' => $quantity,
            'billable_quantity' => $quantity,
            'stock_quantity' => $quantity,
            'unit_price_cents' => 100,
            'base_unit_price_cents' => 100,
            'subtotal_cents' => $total,
            'discount_cents' => 0,
            'total_cents' => $total,
            ...$attributes,
        ]);
    }

    /** @param array<string, string|int> $query */
    protected function report(string $report, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/admin/reports/'.$report.'?'.http_build_query($query));
    }
}
