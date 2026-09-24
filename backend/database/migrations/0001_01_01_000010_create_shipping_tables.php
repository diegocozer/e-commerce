<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE.md §3.8 / SHIPPING.md §5.1 — IBGE cities, carriers, methods, zones
 * (+ postal ranges, cities, states) and rules. `shipping_quotes` is created by
 * the cart migration because it references `carts`.
 */
return new class extends Migration
{
    private const array METHOD_TYPES = ['pickup', 'own_delivery', 'table_rate', 'carrier'];

    public function up(): void
    {
        $this->createIbgeCities();
        $this->createCarriers();
        $this->createMethods();
        $this->createZones();
        $this->createRules();
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rules');
        Schema::dropIfExists('shipping_zone_states');
        Schema::dropIfExists('shipping_zone_cities');
        Schema::dropIfExists('shipping_zone_postal_ranges');
        Schema::dropIfExists('shipping_zones');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('shipping_carriers');
        Schema::dropIfExists('ibge_cities');
    }

    private function createIbgeCities(): void
    {
        Schema::create('ibge_cities', function (Blueprint $table) {
            $table->char('ibge_code', 7)->primary();
            $table->string('name', 100);
            $table->char('state', 2);

            $table->index('state');
        });

        PgSchema::regex('ibge_cities', 'ibge_code', PgSchema::IBGE_CODE_REGEX);
        PgSchema::uf('ibge_cities', 'state');
        PgSchema::index('ibge_cities_name_index', 'ibge_cities', 'lower(public.f_unaccent(name)) text_pattern_ops');
    }

    private function createCarriers(): void
    {
        Schema::create('shipping_carriers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 40)->unique();
            $table->string('driver', 40);
            // Encrypted blob (cast `encrypted:array`), hence text and not jsonb (DB-13).
            $table->text('credentials')->nullable();
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();
        });

        PgSchema::regex('shipping_carriers', 'code', '^[a-z0-9_]+$');
        PgSchema::check('shipping_carriers', 'settings_object', "jsonb_typeof(settings) = 'object'");
    }

    private function createMethods(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 60);
            $table->string('type', 20);
            $table->foreignId('carrier_id')->nullable()->constrained('shipping_carriers')->restrictOnDelete();
            $table->string('carrier_service_code', 40)->nullable();
            $table->string('description', 500)->nullable();
            $table->smallInteger('delivery_days_min')->default(0);
            $table->smallInteger('delivery_days_max')->default(0);
            $table->string('pickup_street', 200)->nullable();
            $table->string('pickup_number', 20)->nullable();
            $table->string('pickup_complement', 100)->nullable();
            $table->string('pickup_district', 100)->nullable();
            $table->string('pickup_city', 100)->nullable();
            $table->char('pickup_state', 2)->nullable();
            $table->char('pickup_postal_code', 8)->nullable();
            $table->string('pickup_instructions', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->string('weight_basis', 20)->default('real');
            $table->integer('cubic_divisor')->nullable();
            $table->smallInteger('handling_days')->default(0);
            // No database default on purpose: the application decides per type (DATABASE.md §3.8.2).
            $table->boolean('accepts_free_shipping_coupon');
            $table->string('pickup_opening_hours', 200)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        PgSchema::regex('shipping_methods', 'code', '^[a-z0-9-]+$');
        PgSchema::enum('shipping_methods', 'type', self::METHOD_TYPES);
        PgSchema::enum('shipping_methods', 'weight_basis', ['real', 'chargeable']);
        PgSchema::uf('shipping_methods', 'pickup_state');
        PgSchema::regex('shipping_methods', 'pickup_postal_code', PgSchema::POSTAL_CODE_REGEX);
        PgSchema::nonNegative('shipping_methods', 'delivery_days_min', 'handling_days');
        PgSchema::positive('shipping_methods', 'cubic_divisor');
        PgSchema::check('shipping_methods', 'delivery_days_range', 'delivery_days_max >= delivery_days_min');
        PgSchema::check('shipping_methods', 'carrier_matches_type', "(type = 'carrier') = (carrier_id IS NOT NULL)");
        PgSchema::check('shipping_methods', 'pickup_address_only_for_pickup', "type = 'pickup' OR num_nonnulls(pickup_street, pickup_city, pickup_state, pickup_postal_code) = 0");
        PgSchema::check('shipping_methods', 'pickup_address_required', "type <> 'pickup' OR (pickup_street IS NOT NULL AND pickup_city IS NOT NULL AND pickup_state IS NOT NULL AND pickup_postal_code IS NOT NULL)");
        PgSchema::uniqueWhere('shipping_methods_code_active_unique', 'shipping_methods', 'code', 'deleted_at IS NULL');
    }

    private function createZones(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('shipping_zone_postal_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->char('start_postal_code', 8);
            $table->char('end_postal_code', 8);
            $table->timestampsTz();

            $table->index(['start_postal_code', 'end_postal_code'], 'shipping_zone_postal_ranges_range_index');
            $table->index('zone_id');
        });

        PgSchema::regex('shipping_zone_postal_ranges', 'start_postal_code', PgSchema::POSTAL_CODE_REGEX);
        PgSchema::regex('shipping_zone_postal_ranges', 'end_postal_code', PgSchema::POSTAL_CODE_REGEX);
        PgSchema::check('shipping_zone_postal_ranges', 'range_order', 'start_postal_code <= end_postal_code');

        Schema::create('shipping_zone_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->char('city_ibge_code', 7);
            $table->string('city_name', 100);
            $table->char('state', 2);
            $table->timestampsTz();

            $table->unique(['zone_id', 'city_ibge_code']);
            $table->index('city_ibge_code');
        });

        PgSchema::regex('shipping_zone_cities', 'city_ibge_code', PgSchema::IBGE_CODE_REGEX);
        PgSchema::uf('shipping_zone_cities', 'state');

        Schema::create('shipping_zone_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->char('state', 2);
            $table->timestampsTz();

            $table->unique(['zone_id', 'state']);
            $table->index('state');
        });

        PgSchema::uf('shipping_zone_states', 'state');
    }

    private function createRules(): void
    {
        Schema::create('shipping_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('method_id')->constrained('shipping_methods')->restrictOnDelete();
            // NULL = any destination covered by the method (never "the whole country").
            $table->foreignId('zone_id')->nullable()->constrained('shipping_zones')->restrictOnDelete();
            $table->string('name', 150);
            $table->integer('priority')->default(100);
            $table->integer('min_weight_grams')->nullable();
            $table->integer('max_weight_grams')->nullable();
            $table->bigInteger('min_subtotal_cents')->nullable();
            $table->bigInteger('max_subtotal_cents')->nullable();
            $table->bigInteger('min_volume_cm3')->nullable();
            $table->bigInteger('max_volume_cm3')->nullable();
            $table->decimal('max_package_length_cm', 8, 1)->nullable();
            $table->string('price_type', 30);
            $table->bigInteger('price_cents')->default(0);
            $table->bigInteger('per_kg_cents')->default(0);
            $table->integer('percentage_bp')->default(0);
            $table->bigInteger('min_price_cents')->nullable();
            $table->bigInteger('max_price_cents')->nullable();
            $table->smallInteger('delivery_days_min')->nullable();
            $table->smallInteger('delivery_days_max')->nullable();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('zone_id');
        });

        $t = 'shipping_rules';
        PgSchema::enum($t, 'price_type', ['fixed', 'per_kg', 'fixed_plus_per_kg', 'percentage_of_subtotal', 'free']);
        PgSchema::positive($t, 'max_package_length_cm');
        PgSchema::nonNegative($t, 'price_cents', 'per_kg_cents', 'min_price_cents', 'delivery_days_min');
        PgSchema::check($t, 'weight_range', 'max_weight_grams IS NULL OR min_weight_grams IS NULL OR max_weight_grams >= min_weight_grams');
        PgSchema::check($t, 'subtotal_range', 'max_subtotal_cents IS NULL OR min_subtotal_cents IS NULL OR max_subtotal_cents >= min_subtotal_cents');
        PgSchema::check($t, 'volume_range', 'max_volume_cm3 IS NULL OR min_volume_cm3 IS NULL OR max_volume_cm3 >= min_volume_cm3');
        PgSchema::check($t, 'price_range', 'max_price_cents IS NULL OR min_price_cents IS NULL OR max_price_cents >= min_price_cents');
        PgSchema::check($t, 'delivery_days_range', 'delivery_days_max IS NULL OR delivery_days_min IS NULL OR delivery_days_max >= delivery_days_min');
        PgSchema::check($t, 'validity_window', 'valid_from IS NULL OR valid_until IS NULL OR valid_from < valid_until');
        PgSchema::check($t, 'free_is_zero', "price_type <> 'free' OR price_cents = 0");
        PgSchema::check($t, 'per_kg_required', "price_type NOT IN ('per_kg','fixed_plus_per_kg') OR per_kg_cents > 0");
        PgSchema::check($t, 'percentage_required', "price_type <> 'percentage_of_subtotal' OR percentage_bp BETWEEN 1 AND 10000");
        PgSchema::check($t, 'percentage_bp_range', 'percentage_bp BETWEEN 0 AND 10000');
        PgSchema::check($t, 'minimums_non_negative', 'min_weight_grams >= 0 AND min_subtotal_cents >= 0 AND min_volume_cm3 >= 0');
        PgSchema::index('shipping_rules_method_priority_index', $t, 'method_id, priority, id', 'is_active AND deleted_at IS NULL');
    }
};
