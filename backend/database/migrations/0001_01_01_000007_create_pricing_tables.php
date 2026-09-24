<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE.md §3.2.2 / §3.2.3 — quantity price tiers and customer/company specific prices. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            // NULL = tier of the variant base price.
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->cascadeOnDelete();
            $table->decimal('min_quantity', 12, 3);
            $table->bigInteger('price_cents');
            $table->timestampsTz();
        });

        PgSchema::positive('price_tiers', 'min_quantity', 'price_cents');
        PgSchema::uniqueNullsNotDistinct('price_tiers_variant_list_qty_unique', 'price_tiers', 'variant_id, price_list_id, min_quantity');

        Schema::create('customer_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->bigInteger('price_cents');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('variant_id');
        });

        PgSchema::positive('customer_prices', 'price_cents');
        PgSchema::check('customer_prices', 'window', 'starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at');
        PgSchema::check('customer_prices', 'single_owner', 'num_nonnulls(customer_id, company_id) = 1');

        foreach (['customer', 'company'] as $owner) {
            DB::statement(<<<SQL
                ALTER TABLE customer_prices ADD CONSTRAINT customer_prices_{$owner}_no_overlap
                    EXCLUDE USING gist ({$owner}_id WITH =, variant_id WITH =,
                        tstzrange(coalesce(starts_at, '-infinity'), coalesce(ends_at, 'infinity'), '[)') WITH &&)
                    WHERE ({$owner}_id IS NOT NULL)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_prices');
        Schema::dropIfExists('price_tiers');
    }
};
