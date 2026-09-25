<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Orders\Models\Order;
use App\Modules\Pricing\Models\Coupon;
use App\Modules\Settings\Models\Setting;
use Illuminate\Support\Facades\DB;

final class OtherReportsTest extends ReportsTestCase
{
    private const array RANGE = ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'];

    public function test_products_groups_per_variant_and_excludes_refunded(): void
    {
        $this->actingAsAdminWith('reports.sales');
        $vinyl = ProductVariant::factory()->create(['product_id' => Product::factory()->linearMeter()]);
        $grommet = ProductVariant::factory()->create();

        $this->item($this->paidOrder('2026-09-02 10:00', 5000), $vinyl, '2.500', 5000, ['sale_unit' => 'LINEAR_METER']);
        $this->item($this->paidOrder('2026-09-03 10:00', 3000), $vinyl, '1.500', 3000, ['sale_unit' => 'LINEAR_METER']);
        $this->item($this->paidOrder('2026-09-03 11:00', 1000), $grommet, '10', 1000);
        $this->item($this->refund($this->paidOrder('2026-09-04 10:00', 9000), '2026-09-05 10:00'), $grommet, '90', 9000);

        $this->report('products', self::RANGE)->assertOk()
            ->assertJsonPath('data.summary', ['distinct_variants' => 2, 'revenue_cents' => 9000])
            ->assertJsonPath('data.rows.0.variant_id', $vinyl->id)
            ->assertJsonPath('data.rows.0.sale_unit', 'LINEAR_METER')
            ->assertJsonPath('data.rows.0.quantity', 4)
            ->assertJsonPath('data.rows.0.orders_count', 2)
            ->assertJsonPath('data.rows.1.quantity', 10)
            ->assertJsonPath('data.rows.1.revenue_cents', 1000);

        $this->report('products', [...self::RANGE, 'limit' => 1])->assertOk()->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.summary.distinct_variants', 2);
        $this->report('products', [...self::RANGE, 'brand_id' => 999999])->assertOk()->assertJsonCount(0, 'data.rows');
    }

    public function test_margin_uses_variant_cost_and_reports_coverage(): void
    {
        $this->actingAsAdminWith('reports.view');
        $withCost = ProductVariant::factory()->create(['cost_cents' => 300]);
        $withoutCost = ProductVariant::factory()->create(['cost_cents' => null]);
        $order = $this->paidOrder('2026-09-02 10:00', 3000);
        $this->item($order, $withCost, '4', 2000);
        $this->item($order, $withoutCost, '1', 1000);

        $response = $this->report('margin', self::RANGE)->assertOk()
            ->assertJsonPath('data.summary', ['revenue_cents' => 3000, 'cost_cents' => 1200, 'margin_cents' => 800, 'cost_coverage_bp' => 6667]);
        $rows = collect($response->json('data.rows'))->keyBy('variant_id');
        self::assertSame(['cost_cents' => 1200, 'margin_cents' => 800, 'margin_bp' => 4000], array_intersect_key($rows[$withCost->id], array_flip(['cost_cents', 'margin_cents', 'margin_bp'])));
        self::assertNull($rows[$withoutCost->id]['margin_cents']);
    }

    public function test_customers_summary_new_returning_and_pf_pj(): void
    {
        $this->actingAsAdminWith('reports.view');
        $this->travelTo(self::sp('2026-08-10 10:00'));
        $loyal = Customer::factory()->create();
        $this->travelTo(self::sp('2026-09-05 10:00'));
        $company = Customer::factory()->company()->create();
        $this->travelBack();

        $this->paidOrder('2026-08-15 10:00', 1000, [], $loyal);
        $this->paidOrder('2026-09-10 10:00', 5000, [], $loyal);
        $this->paidOrder('2026-09-11 10:00', 9000, ['customer_type' => 'company'], $company);

        $this->report('customers', self::RANGE)->assertOk()
            ->assertJsonPath('data.summary', ['new_customers' => 1, 'returning_customers' => 1, 'individual_customers' => 1, 'company_customers' => 1])
            ->assertJsonPath('data.rows.0.customer_id', $company->id)
            ->assertJsonPath('data.rows.0.type', 'company')
            ->assertJsonPath('data.rows.0.revenue_cents', 11000)
            ->assertJsonPath('data.rows.1.orders_count', 1);
    }

    public function test_orders_conversion_and_cancellations(): void
    {
        $this->actingAsAdminWith('reports.view');
        $this->paidOrder('2026-09-02 10:00', 1000);
        Order::factory()->create(['placed_at' => self::sp('2026-09-02 10:00')]);
        Order::factory()->create(['placed_at' => self::sp('2026-09-02 10:00'), 'status' => 'cancelled', 'payment_status' => 'expired', 'cancelled_at' => now(), 'cancel_reason_code' => 'payment_expired']);
        Order::factory()->create(['placed_at' => self::sp('2026-09-03 10:00'), 'status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason_code' => 'customer']);
        Order::factory()->create(['placed_at' => self::sp('2026-10-01 00:00')]); // outside

        $this->report('orders', self::RANGE)->assertOk()
            ->assertJsonPath('data.summary.created', 4)
            ->assertJsonPath('data.summary.paid', 1)
            ->assertJsonPath('data.summary.cancelled', 2)
            ->assertJsonPath('data.summary.cancelled_payment_expired', 1)
            ->assertJsonPath('data.summary.cancelled_customer', 1)
            ->assertJsonPath('data.summary.conversion_bp', 2500)
            ->assertJsonPath('data.summary.cancellation_bp', 5000)
            ->assertJsonCount(8, 'data.rows');

        $this->report('orders', [...self::RANGE, 'status' => 'cancelled'])->assertOk()
            ->assertJsonPath('data.rows', [['status' => 'cancelled', 'count' => 2]]);
    }

    public function test_shipping_by_method_and_free_shipping(): void
    {
        $this->actingAsAdminWith('reports.view');
        $this->paidOrder('2026-09-02 10:00', 1000, ['shipping_cents' => 2000]);
        $this->paidOrder('2026-09-02 11:00', 1000, ['shipping_cents' => 1500, 'shipping_discount_cents' => 1500]);
        Order::factory()->pickup()->paid()->create(['paid_at' => self::sp('2026-09-03 10:00')]);

        $response = $this->report('shipping', self::RANGE)->assertOk()
            ->assertJsonPath('data.summary', ['orders' => 3, 'shipping_revenue_cents' => 2000, 'free_shipping_orders' => 1, 'avg_shipping_cents' => 1000, 'free_shipping_bp' => 5000]);
        $delivery = collect($response->json('data.rows'))->firstWhere('method_type', 'own_delivery');
        self::assertEquals(['orders_count' => 2, 'shipping_revenue_cents' => 2000, 'free_shipping_orders' => 1, 'shipping_discount_cents' => 1500, 'city' => 'Blumenau'],
            array_intersect_key($delivery, array_flip(['orders_count', 'shipping_revenue_cents', 'free_shipping_orders', 'shipping_discount_cents', 'city'])));
    }

    public function test_coupons_usage(): void
    {
        $this->actingAsAdminWith('reports.view');
        $coupon = Coupon::factory()->create();
        $this->paidOrder('2026-09-02 10:00', 10000, ['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code, 'discount_cents' => 1000]);
        $this->paidOrder('2026-09-03 10:00', 10000);

        $this->report('coupons', self::RANGE)->assertOk()
            ->assertJsonPath('data.summary', ['uses' => 1, 'discount_cents' => 1000])
            ->assertJsonPath('data.rows.0.code', $coupon->code)
            ->assertJsonPath('data.rows.0.orders_revenue_cents', 11000);
    }

    public function test_inventory_valuation_low_stock_and_movements(): void
    {
        $this->actingAsAdminWith('reports.inventory');
        Setting::query()->create(['key' => 'inventory.default_low_stock_threshold', 'value' => 5, 'group' => 'inventory']);
        $low = ProductVariant::factory()->withStock('6', '2')->create(['cost_cents' => 250]);   // available 4 ≤ 5
        $ok = ProductVariant::factory()->withStock('100')->create(['cost_cents' => null]);
        DB::table('inventory')->where('variant_id', $ok->id)->update(['low_stock_threshold' => '1']);
        $this->item($this->paidOrder('2026-09-02 10:00', 1000), $low, '3', 1000);
        InventoryMovement::factory()->create(['variant_id' => $low->id, 'created_at' => self::sp('2026-09-02 10:00')]);

        $response = $this->report('inventory', self::RANGE)->assertOk()
            ->assertJsonPath('data.summary', ['variants' => 2, 'low_stock_variants' => 1, 'stock_value_cents' => 1500])
            ->assertJsonPath('data.rows.0.variant_id', $low->id)
            ->assertJsonPath('data.rows.0.is_low_stock', true)
            ->assertJsonPath('data.rows.0.available', 4)
            ->assertJsonPath('data.rows.0.low_stock_threshold', 5)
            ->assertJsonPath('data.rows.0.stock_value_cents', 1500)
            ->assertJsonPath('data.rows.0.sold_quantity', 3)
            ->assertJsonPath('data.rows.1.stock_value_cents', null);
        self::assertSame(1, $response->json('data.rows.1.low_stock_threshold'));

        $this->report('inventory-movements', self::RANGE)->assertOk()
            ->assertJsonPath('data.summary.movements', 1)
            ->assertJsonPath('data.rows.0.variant_id', $low->id);
    }
}
