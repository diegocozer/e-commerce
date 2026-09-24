<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE.md §3.3 — stock balance (1:1 with variant) and immutable movements. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->unique()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('on_hand', 12, 3)->default(0);
            $table->decimal('reserved', 12, 3)->default(0);
            $table->decimal('low_stock_threshold', 12, 3)->nullable();
            $table->timestampTz('low_stock_alerted_at')->nullable();
            $table->timestampsTz();
        });

        PgSchema::check('inventory', 'on_hand_non_negative', 'on_hand >= 0');
        PgSchema::check('inventory', 'reserved_non_negative', 'reserved >= 0');
        PgSchema::check('inventory', 'reserved_lte_on_hand', 'reserved <= on_hand');
        PgSchema::check('inventory', 'low_stock_threshold_non_negative', 'low_stock_threshold IS NULL OR low_stock_threshold >= 0');

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('type', 10);
            $table->decimal('quantity', 12, 3);
            $table->decimal('on_hand_delta', 12, 3);
            $table->decimal('reserved_delta', 12, 3);
            $table->decimal('on_hand_after', 12, 3);
            $table->decimal('reserved_after', 12, 3);
            $table->string('reason', 255)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('created_at')->default(DB::raw('now()'));

            $table->index(['reference_type', 'reference_id'], 'inventory_movements_reference_index');
            $table->index('created_at');
        });

        PgSchema::enum('inventory_movements', 'type', ['in', 'out', 'reserve', 'release', 'return', 'adjust']);
        PgSchema::positive('inventory_movements', 'quantity');
        PgSchema::check('inventory_movements', 'on_hand_after_non_negative', 'on_hand_after >= 0');
        PgSchema::check('inventory_movements', 'reserved_after_range', 'reserved_after >= 0 AND reserved_after <= on_hand_after');
        PgSchema::check('inventory_movements', 'reference_pair', 'num_nulls(reference_type, reference_id) IN (0, 2)');
        PgSchema::check('inventory_movements', 'type_delta', <<<'SQL'
            (type = 'in'      AND on_hand_delta =  quantity AND reserved_delta = 0) OR
            (type = 'out'     AND on_hand_delta = -quantity AND reserved_delta = -quantity) OR
            (type = 'reserve' AND on_hand_delta = 0         AND reserved_delta =  quantity) OR
            (type = 'release' AND on_hand_delta = 0         AND reserved_delta = -quantity) OR
            (type = 'return'  AND on_hand_delta =  quantity AND reserved_delta = 0) OR
            (type = 'adjust'  AND abs(on_hand_delta) = quantity AND reserved_delta = 0)
            SQL);
        PgSchema::index('inventory_movements_variant_created_index', 'inventory_movements', 'variant_id, created_at DESC');
        PgSchema::immutable('inventory_movements');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory');
    }
};
