<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE.md §3.2.1 — price lists (created early: companies/customers reference them). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('kind', 20)->default('custom');
            $table->integer('discount_bp')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        PgSchema::regex('price_lists', 'code', '^[a-z0-9_]+$');
        PgSchema::enum('price_lists', 'kind', ['retail', 'wholesale', 'reseller', 'custom']);
        PgSchema::check('price_lists', 'discount_bp_range', 'discount_bp IS NULL OR discount_bp BETWEEN 1 AND 9999');
        PgSchema::uniqueWhere('price_lists_is_default_unique', 'price_lists', 'is_default', 'is_default');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
