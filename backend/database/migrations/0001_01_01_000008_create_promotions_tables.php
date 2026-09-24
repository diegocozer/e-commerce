<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE.md §3.2.4–§3.2.6 — automatic promotions, their targets (3 pivots, DB-08) and coupons. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->string('discount_type', 10);
            $table->bigInteger('value');
            $table->string('scope', 10)->default('targeted');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(100);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        PgSchema::enum('promotions', 'discount_type', ['percent', 'fixed']);
        PgSchema::enum('promotions', 'scope', ['all', 'targeted']);
        PgSchema::positive('promotions', 'value');
        PgSchema::check('promotions', 'percent_range', "discount_type <> 'percent' OR value BETWEEN 1 AND 10000");
        PgSchema::check('promotions', 'window', 'ends_at IS NULL OR ends_at > starts_at');
        PgSchema::index('promotions_active_window_index', 'promotions', 'starts_at, ends_at', 'is_active AND deleted_at IS NULL');

        $targets = ['promotion_products' => ['product_id', 'products'], 'promotion_categories' => ['category_id', 'categories'], 'promotion_brands' => ['brand_id', 'brands']];
        foreach ($targets as $pivot => [$column, $targetTable]) {
            Schema::create($pivot, function (Blueprint $table) use ($column, $targetTable) {
                $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
                $table->foreignId($column)->constrained($targetTable)->cascadeOnDelete();

                $table->primary(['promotion_id', $column]);
                $table->index($column);
            });
        }

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('description', 255)->nullable();
            $table->string('type', 20);
            $table->bigInteger('value')->default(0);
            $table->bigInteger('min_order_cents')->default(0);
            $table->bigInteger('max_discount_cents')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_limit_per_customer')->nullable();
            $table->integer('times_used')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        PgSchema::check('coupons', 'code_format', "code = upper(code) AND code ~ '^[A-Z0-9_-]{3,40}$'");
        PgSchema::enum('coupons', 'type', ['percent', 'fixed', 'free_shipping']);
        PgSchema::check('coupons', 'value_by_type', <<<'SQL'
            (type = 'percent' AND value BETWEEN 1 AND 10000)
            OR (type = 'fixed' AND value > 0)
            OR (type = 'free_shipping' AND value = 0)
            SQL);
        PgSchema::nonNegative('coupons', 'min_order_cents', 'times_used');
        PgSchema::positive('coupons', 'max_discount_cents', 'usage_limit', 'usage_limit_per_customer');
        PgSchema::check('coupons', 'window', 'ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at');
        PgSchema::uniqueWhere('coupons_code_active_unique', 'coupons', 'code', 'deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('promotion_brands');
        Schema::dropIfExists('promotion_categories');
        Schema::dropIfExists('promotion_products');
        Schema::dropIfExists('promotions');
    }
};
