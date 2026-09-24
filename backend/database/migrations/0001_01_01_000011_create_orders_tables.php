<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE.md §3.6 / §3.2.7 — order number sequence, orders (full snapshot),
 * immutable items and status history, coupon redemptions.
 */
return new class extends Migration
{
    private const array ORDER_STATUSES = ['pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'ready_for_pickup', 'picked_up', 'cancelled'];

    private const array SALE_UNITS = ['UNIT', 'LINEAR_METER', 'SQUARE_METER', 'ROLL', 'KG', 'BOX'];

    public function up(): void
    {
        // DB-11: formatted by the application ("CV-" + at least 6 digits). Recreated on
        // every run because `migrate:fresh` does not drop standalone sequences.
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
        DB::statement('CREATE SEQUENCE order_number_seq AS bigint START WITH 1 INCREMENT BY 1 NO CYCLE');

        $this->createOrders();
        $this->createOrderItems();
        $this->createStatusHistory();
        $this->createCouponRedemptions();
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }

    private function createOrders(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->string('number', 20)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('checkout_fingerprint', 64);
            $table->string('status', 20)->default('pending_payment');
            $table->string('payment_status', 10)->default('pending');
            $table->string('payment_method', 20);
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('shipping_cents')->default(0);
            $table->bigInteger('shipping_discount_cents')->default(0);
            $table->bigInteger('total_cents');
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->restrictOnDelete();
            $table->string('coupon_code', 40)->nullable();
            // Customer snapshot
            $table->string('customer_type', 10);
            $table->string('customer_name', 150);
            $table->string('customer_email', 255);
            $table->string('customer_document', 14);
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_company_name', 200)->nullable();
            $table->string('customer_state_registration', 20)->nullable();
            // Shipping address snapshot
            $table->foreignId('customer_address_id')->nullable()->constrained('customer_addresses')->restrictOnDelete();
            $table->string('shipping_recipient_name', 150)->nullable();
            $table->string('shipping_phone', 20)->nullable();
            $table->char('shipping_postal_code', 8)->nullable();
            $table->string('shipping_street', 200)->nullable();
            $table->string('shipping_number', 20)->nullable();
            $table->string('shipping_complement', 100)->nullable();
            $table->string('shipping_district', 100)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->char('shipping_state', 2)->nullable();
            $table->char('shipping_city_ibge_code', 7)->nullable();
            $table->string('shipping_reference', 200)->nullable();
            // Shipping method snapshot
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->restrictOnDelete();
            $table->foreignId('shipping_rule_id')->nullable()->constrained('shipping_rules')->restrictOnDelete();
            $table->string('shipping_option_id', 100);
            $table->string('shipping_method_name', 120);
            $table->string('shipping_method_type', 20);
            $table->string('shipping_carrier_code', 40)->nullable();
            $table->string('shipping_service_code', 40)->nullable();
            $table->smallInteger('shipping_delivery_days_min')->nullable();
            $table->smallInteger('shipping_delivery_days_max')->nullable();
            $table->uuid('shipping_quote_uuid')->nullable();
            $table->integer('total_weight_grams')->default(0);
            $table->bigInteger('total_volume_cm3')->default(0);
            $table->string('notes', 500)->nullable();
            $table->text('internal_notes')->nullable();
            $table->ipAddress('placed_ip')->nullable();
            // Lifecycle
            $table->timestampTz('placed_at')->default(DB::raw('now()'));
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('ready_for_pickup_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->string('picked_up_by_document', 20)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason_code', 20)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestampTz('cancellation_requested_at')->nullable();
            $table->string('cancellation_request_reason', 500)->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->string('tracking_code', 100)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->string('picked_up_by_name', 150)->nullable();
            $table->timestampsTz();

            $table->unique(['customer_id', 'idempotency_key'], 'orders_customer_idempotency_unique');
            $table->index('customer_document');
            $table->index('customer_email');
            $table->index('shipping_method_id');
        });

        $t = 'orders';
        PgSchema::regex($t, 'number', '^[A-Z]{1,5}-[0-9]{6,}$');
        PgSchema::enum($t, 'status', self::ORDER_STATUSES);
        PgSchema::enum($t, 'payment_status', ['pending', 'approved', 'failed', 'refunded', 'expired']);
        PgSchema::enum($t, 'payment_method', ['pix', 'credit_card', 'boleto', 'invoice']);
        PgSchema::enum($t, 'customer_type', ['individual', 'company']);
        PgSchema::enum($t, 'shipping_method_type', ['pickup', 'own_delivery', 'table_rate', 'carrier']);
        PgSchema::enum($t, 'cancel_reason_code', ['payment_expired', 'customer', 'admin', 'payment_failed']);
        PgSchema::uf($t, 'shipping_state');
        PgSchema::regex($t, 'shipping_postal_code', PgSchema::POSTAL_CODE_REGEX);
        PgSchema::regex($t, 'shipping_city_ibge_code', PgSchema::IBGE_CODE_REGEX);
        PgSchema::check($t, 'customer_document', "customer_document ~ '^([0-9]{11}|[0-9A-Z]{12}[0-9]{2})$'");
        PgSchema::nonNegative($t, 'subtotal_cents', 'discount_cents', 'shipping_cents', 'shipping_discount_cents', 'total_cents', 'total_weight_grams', 'total_volume_cm3', 'shipping_delivery_days_min');
        PgSchema::check($t, 'total', 'total_cents = subtotal_cents - discount_cents + shipping_cents - shipping_discount_cents');
        PgSchema::check($t, 'discount_lte_subtotal', 'discount_cents <= subtotal_cents');
        PgSchema::check($t, 'shipping_discount_lte_shipping', 'shipping_discount_cents <= shipping_cents');
        PgSchema::check($t, 'coupon_snapshot', '(coupon_id IS NULL) = (coupon_code IS NULL)');
        PgSchema::check($t, 'delivery_days_range', 'shipping_delivery_days_max IS NULL OR shipping_delivery_days_min IS NULL OR shipping_delivery_days_max >= shipping_delivery_days_min');
        PgSchema::check($t, 'shipping_address_required', <<<'SQL'
            shipping_method_type = 'pickup' OR (shipping_postal_code IS NOT NULL
                AND shipping_street IS NOT NULL AND shipping_number IS NOT NULL
                AND shipping_district IS NOT NULL AND shipping_city IS NOT NULL
                AND shipping_state IS NOT NULL AND shipping_recipient_name IS NOT NULL)
            SQL);
        PgSchema::check($t, 'cancelled_fields', "status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancel_reason_code IS NOT NULL)");
        PgSchema::check($t, 'refunded_fields', "payment_status <> 'refunded' OR refunded_at IS NOT NULL");
        PgSchema::check($t, 'picked_up_fields', "status <> 'picked_up' OR (picked_up_at IS NOT NULL AND picked_up_by_name IS NOT NULL)");
        PgSchema::check($t, 'paid_fields', "status NOT IN ('paid','processing','shipped','delivered','ready_for_pickup','picked_up') OR paid_at IS NOT NULL");
        PgSchema::check($t, 'pickup_statuses', "status NOT IN ('ready_for_pickup','picked_up') OR shipping_method_type = 'pickup'");
        PgSchema::check($t, 'delivery_statuses', "status NOT IN ('shipped','delivered') OR shipping_method_type <> 'pickup'");

        PgSchema::index('orders_customer_pending_index', $t, 'customer_id', "status = 'pending_payment'");
        PgSchema::index('orders_customer_placed_index', $t, 'customer_id, placed_at DESC');
        PgSchema::index('orders_status_placed_index', $t, 'status, placed_at DESC');
        PgSchema::index('orders_payment_status_placed_index', $t, 'payment_status, placed_at DESC');
        PgSchema::index('orders_placed_at_index', $t, 'placed_at DESC');
        PgSchema::index('orders_paid_report_index', $t, 'paid_at', "payment_status = 'approved'");
        PgSchema::index('orders_pending_expiry_index', $t, 'expires_at', "status = 'pending_payment'");
        PgSchema::index('orders_coupon_id_index', $t, 'coupon_id', 'coupon_id IS NOT NULL');
        PgSchema::index('orders_refunded_at_index', $t, 'refunded_at', 'refunded_at IS NOT NULL');
        PgSchema::index('orders_cancellation_requested_index', $t, 'cancellation_requested_at', "cancellation_requested_at IS NOT NULL AND status IN ('paid','processing')");
    }

    private function createOrderItems(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name', 200);
            $table->string('variant_name', 200);
            $table->string('sku', 40);
            $table->string('sale_unit', 20);
            $table->decimal('quantity', 12, 3)->nullable();
            $table->integer('width_mm')->nullable();
            $table->integer('height_mm')->nullable();
            $table->integer('pieces')->nullable();
            $table->decimal('billable_quantity', 12, 3);
            $table->decimal('stock_quantity', 12, 3);
            $table->bigInteger('base_unit_price_cents');
            $table->bigInteger('unit_price_cents');
            $table->string('price_source', 20);
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->restrictOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->restrictOnDelete();
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('total_cents');
            $table->integer('weight_grams');
            $table->timestampTz('created_at')->default(DB::raw('now()'));

            $table->index('order_id');
            $table->index('variant_id');
            $table->index('product_id');
        });

        $t = 'order_items';
        PgSchema::enum($t, 'sale_unit', self::SALE_UNITS);
        PgSchema::enum($t, 'price_source', ['base', 'tier', 'price_list', 'variant_promo', 'promotion', 'customer_price']);
        PgSchema::positive($t, 'quantity', 'billable_quantity', 'stock_quantity', 'base_unit_price_cents', 'unit_price_cents');
        PgSchema::nonNegative($t, 'subtotal_cents', 'discount_cents', 'weight_grams');
        PgSchema::check($t, 'total', 'total_cents = subtotal_cents - discount_cents');
        PgSchema::check($t, 'discount_lte_subtotal', 'discount_cents <= subtotal_cents');
        PgSchema::check($t, 'shape', <<<'SQL'
            (quantity IS NOT NULL AND width_mm IS NULL AND height_mm IS NULL AND pieces IS NULL)
            OR (quantity IS NULL AND width_mm > 0 AND height_mm > 0 AND pieces > 0)
            SQL);
        PgSchema::check($t, 'square_meter_shape', "(sale_unit = 'SQUARE_METER') = (quantity IS NULL)");
        PgSchema::check($t, 'non_area_quantities', "sale_unit = 'SQUARE_METER' OR (billable_quantity = quantity AND stock_quantity = quantity)");
        PgSchema::immutable($t);
    }

    private function createStatusHistory(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('actor_type', 10);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestampTz('created_at')->default(DB::raw('now()'));

            $table->index(['order_id', 'created_at'], 'order_status_history_order_created_index');
        });

        $t = 'order_status_history';
        PgSchema::enum($t, 'from_status', self::ORDER_STATUSES);
        PgSchema::enum($t, 'to_status', self::ORDER_STATUSES);
        PgSchema::enum($t, 'actor_type', ['admin', 'customer', 'system']);
        PgSchema::check($t, 'system_actor', "(actor_type = 'system') = (actor_id IS NULL)");
        PgSchema::check($t, 'status_changes', 'from_status IS DISTINCT FROM to_status');
        PgSchema::check($t, 'reactivation', "from_status IS DISTINCT FROM 'cancelled' OR (to_status = 'paid' AND actor_type = 'system')");
        PgSchema::immutable($t);
    }

    private function createCouponRedemptions(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->bigInteger('discount_cents');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
            // Only column that may be updated (order cancelled/expired: usage given back).
            $table->timestampTz('cancelled_at')->nullable();
        });

        PgSchema::nonNegative('coupon_redemptions', 'discount_cents');
        PgSchema::index('coupon_redemptions_coupon_customer_active_index', 'coupon_redemptions', 'coupon_id, customer_id', 'cancelled_at IS NULL');
    }
};
