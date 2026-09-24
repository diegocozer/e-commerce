<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE.md §3.5 / §3.8.5 — carts, cart items and persisted shipping quotes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            // Value of the X-Cart-Token header (ADR-007).
            $table->uuid('token')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->char('postal_code', 8)->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('converted_at')->nullable();
            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->timestampsTz();
        });

        PgSchema::regex('carts', 'postal_code', PgSchema::POSTAL_CODE_REGEX);
        PgSchema::check('carts', 'conversion_pair', '(converted_at IS NULL) = (converted_order_id IS NULL)');
        PgSchema::uniqueWhere('carts_customer_active_unique', 'carts', 'customer_id', 'customer_id IS NOT NULL AND converted_at IS NULL');
        PgSchema::index('carts_expires_at_index', 'carts', 'expires_at', 'converted_at IS NULL');

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            // NULL for SQUARE_METER (width/height/pieces instead) — DB-10.
            $table->decimal('quantity', 12, 3)->nullable();
            $table->integer('width_mm')->nullable();
            $table->integer('height_mm')->nullable();
            $table->integer('pieces')->nullable();
            // ADR-028: unit price the customer last saw (drives the "price changed" notice).
            $table->bigInteger('last_seen_unit_price_cents')->nullable();
            $table->timestampsTz();

            $table->index('variant_id');
        });

        PgSchema::positive('cart_items', 'quantity', 'width_mm', 'height_mm', 'pieces', 'last_seen_unit_price_cents');
        PgSchema::check('cart_items', 'shape', <<<'SQL'
            (quantity IS NOT NULL AND width_mm IS NULL AND height_mm IS NULL AND pieces IS NULL) OR
            (quantity IS NULL AND width_mm IS NOT NULL AND height_mm IS NOT NULL AND pieces IS NOT NULL)
            SQL);
        PgSchema::uniqueNullsNotDistinct('cart_items_merge_unique', 'cart_items', 'cart_id, variant_id, width_mm, height_mm');

        Schema::create('shipping_quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->char('postal_code', 8);
            $table->char('city_ibge_code', 7)->nullable();
            $table->char('state', 2)->nullable();
            $table->char('request_hash', 64);
            $table->bigInteger('subtotal_cents');
            $table->integer('total_weight_grams');
            $table->bigInteger('total_volume_cm3');
            $table->boolean('coupon_free_shipping')->default(false);
            $table->jsonb('options');
            $table->jsonb('unavailable')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->default(DB::raw('now()'));

            $table->index('expires_at');
        });

        $t = 'shipping_quotes';
        PgSchema::regex($t, 'postal_code', PgSchema::POSTAL_CODE_REGEX);
        PgSchema::regex($t, 'city_ibge_code', PgSchema::IBGE_CODE_REGEX);
        PgSchema::uf($t, 'state');
        PgSchema::nonNegative($t, 'subtotal_cents', 'total_weight_grams', 'total_volume_cm3');
        PgSchema::check($t, 'options_array', "jsonb_typeof(options) = 'array'");
        PgSchema::check($t, 'unavailable_array', "jsonb_typeof(unavailable) = 'array'");
        PgSchema::index('shipping_quotes_cart_hash_index', $t, 'cart_id, request_hash, expires_at DESC');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_quotes');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
