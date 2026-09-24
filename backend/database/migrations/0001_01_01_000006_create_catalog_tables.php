<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE.md §3.1 — categories, brands, products, product_categories, product_variants, product_images. */
return new class extends Migration
{
    private const array SALE_UNITS = ['UNIT', 'LINEAR_METER', 'SQUARE_METER', 'ROLL', 'KG', 'BOX'];

    public function up(): void
    {
        $this->createCategories();
        $this->createBrands();
        $this->createProducts();
        $this->createProductCategories();
        $this->createProductVariants();
        $this->createProductImages();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }

    private function createCategories(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->text('description')->nullable();
            $table->string('image_path', 500)->nullable();
            $table->string('meta_title', 120)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['parent_id', 'position']);
        });

        PgSchema::check('categories', 'not_own_parent', 'parent_id <> id');
        PgSchema::regex('categories', 'slug', PgSchema::SLUG_REGEX);
        PgSchema::uniqueWhere('categories_slug_active_unique', 'categories', 'slug', 'deleted_at IS NULL');
    }

    private function createBrands(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('logo_path', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        PgSchema::regex('brands', 'slug', PgSchema::SLUG_REGEX);
        PgSchema::uniqueWhere('brands_slug_active_unique', 'brands', 'slug', 'deleted_at IS NULL');
    }

    private function createProducts(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('slug', 220);
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('sale_unit', 20);
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->foreignId('primary_category_id')->constrained('categories')->restrictOnDelete();
            $table->decimal('min_quantity', 12, 3)->default(1);
            $table->decimal('max_quantity', 12, 3)->nullable();
            $table->decimal('quantity_step', 12, 3)->default(1);
            $table->decimal('min_billable_area_m2', 12, 3)->nullable();
            $table->integer('fixed_width_mm')->nullable();
            $table->integer('min_width_mm')->nullable();
            $table->integer('max_width_mm')->nullable();
            $table->integer('min_height_mm')->nullable();
            $table->integer('max_height_mm')->nullable();
            $table->string('meta_title', 120)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('pickup_only')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('primary_category_id');
            $table->index('brand_id');
        });

        // Maintained by the application (ProductSearchIndexer), not GENERATED: it
        // includes brand and category names from other tables (DB-06).
        DB::statement('ALTER TABLE products ADD COLUMN search_vector tsvector NULL');

        PgSchema::regex('products', 'slug', PgSchema::SLUG_REGEX);
        PgSchema::enum('products', 'sale_unit', self::SALE_UNITS);
        PgSchema::check('products', 'min_quantity_positive', 'min_quantity > 0');
        PgSchema::check('products', 'quantity_step_positive', 'quantity_step > 0');
        PgSchema::check('products', 'max_quantity_gte_min', 'max_quantity IS NULL OR max_quantity >= min_quantity');
        PgSchema::check('products', 'integer_units', <<<'SQL'
            sale_unit NOT IN ('UNIT','ROLL','BOX','SQUARE_METER')
            OR (min_quantity = trunc(min_quantity) AND quantity_step = trunc(quantity_step)
                AND (max_quantity IS NULL OR max_quantity = trunc(max_quantity)))
            SQL);
        PgSchema::check('products', 'area_fields_square_meter_only', <<<'SQL'
            sale_unit = 'SQUARE_METER' OR (min_billable_area_m2 IS NULL
                AND min_width_mm IS NULL AND max_width_mm IS NULL
                AND min_height_mm IS NULL AND max_height_mm IS NULL)
            SQL);
        PgSchema::check('products', 'fixed_width_unit', "fixed_width_mm IS NULL OR sale_unit IN ('SQUARE_METER','LINEAR_METER')");
        PgSchema::check('products', 'fixed_width_xor_range', 'fixed_width_mm IS NULL OR (min_width_mm IS NULL AND max_width_mm IS NULL)');
        PgSchema::check('products', 'min_billable_area_positive', 'min_billable_area_m2 IS NULL OR min_billable_area_m2 > 0');
        PgSchema::check('products', 'fixed_width_positive', 'fixed_width_mm IS NULL OR fixed_width_mm > 0');
        PgSchema::check('products', 'min_width_positive', 'min_width_mm IS NULL OR min_width_mm > 0');
        PgSchema::check('products', 'min_height_positive', 'min_height_mm IS NULL OR min_height_mm > 0');
        PgSchema::check('products', 'max_width_gte_min', 'max_width_mm IS NULL OR min_width_mm IS NULL OR max_width_mm >= min_width_mm');
        PgSchema::check('products', 'max_height_gte_min', 'max_height_mm IS NULL OR min_height_mm IS NULL OR max_height_mm >= min_height_mm');

        PgSchema::uniqueWhere('products_slug_active_unique', 'products', 'slug', 'deleted_at IS NULL');
        PgSchema::index('products_listing_index', 'products', 'is_featured, created_at DESC', 'is_active AND deleted_at IS NULL');
        PgSchema::index('products_search_vector_index', 'products', 'search_vector', using: 'GIN');
        PgSchema::index('products_name_trgm_index', 'products', 'name gin_trgm_ops', using: 'GIN');
    }

    private function createProductCategories(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->integer('position')->default(0);

            $table->primary(['product_id', 'category_id']);
            $table->index(['category_id', 'position']);
        });
    }

    private function createProductVariants(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('sku', 40);
            $table->string('gtin', 14)->nullable();
            $table->string('name', 200);
            $table->jsonb('attributes')->default(DB::raw("'{}'::jsonb"));
            $table->bigInteger('price_cents');
            $table->bigInteger('promo_price_cents')->nullable();
            $table->timestampTz('promo_starts_at')->nullable();
            $table->timestampTz('promo_ends_at')->nullable();
            $table->bigInteger('cost_cents')->nullable();
            $table->integer('weight_grams')->default(0);
            $table->decimal('package_length_cm', 8, 1)->nullable();
            $table->decimal('package_width_cm', 8, 1)->nullable();
            $table->decimal('package_height_cm', 8, 1)->nullable();
            $table->decimal('roll_length_m', 10, 3)->nullable();
            $table->integer('units_per_box')->nullable();
            $table->integer('units_per_package')->nullable();
            $table->integer('fixed_width_mm')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['product_id', 'position']);
        });

        PgSchema::regex('product_variants', 'sku', '^[A-Z0-9-]{3,40}$');
        PgSchema::regex('product_variants', 'gtin', '^[0-9]{8,14}$');
        PgSchema::check('product_variants', 'attributes_object', "jsonb_typeof(attributes) = 'object'");
        PgSchema::positive('product_variants', 'price_cents');
        PgSchema::check('product_variants', 'promo_price_range', 'promo_price_cents IS NULL OR (promo_price_cents > 0 AND promo_price_cents < price_cents)');
        PgSchema::check('product_variants', 'promo_window', 'promo_starts_at IS NULL OR promo_ends_at IS NULL OR promo_ends_at > promo_starts_at');
        PgSchema::nonNegative('product_variants', 'cost_cents', 'weight_grams');
        PgSchema::positive('product_variants', 'package_length_cm', 'package_width_cm', 'package_height_cm', 'roll_length_m', 'units_per_box', 'units_per_package', 'fixed_width_mm');

        PgSchema::uniqueWhere('product_variants_sku_active_unique', 'product_variants', 'sku text_pattern_ops', 'deleted_at IS NULL');
        PgSchema::index('product_variants_product_price_index', 'product_variants', 'product_id, price_cents', 'is_active AND deleted_at IS NULL');
    }

    private function createProductImages(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('disk', 20)->default('s3');
            $table->string('path', 500);
            $table->string('alt', 255)->nullable();
            $table->integer('width_px')->nullable();
            $table->integer('height_px')->nullable();
            $table->integer('position')->default(0);
            $table->timestampsTz();

            $table->index(['product_id', 'position']);
            $table->index('variant_id');
        });

        PgSchema::positive('product_images', 'width_px', 'height_px');
    }
};
