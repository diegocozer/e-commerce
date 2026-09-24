<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE.md §3.10 — audit_logs (immutable), notifications and settings. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 10);
            // No FK: polymorphic (admin_users or customers) and must survive deletions.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('auditable_type', 40)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->default(DB::raw('now()'));
        });

        $t = 'audit_logs';
        PgSchema::enum($t, 'actor_type', ['admin', 'customer', 'system']);
        PgSchema::check($t, 'system_actor', "(actor_type = 'system') = (actor_id IS NULL)");
        PgSchema::check($t, 'auditable_pair', 'num_nulls(auditable_type, auditable_id) IN (0, 2)');
        PgSchema::check($t, 'old_values_object', "old_values IS NULL OR jsonb_typeof(old_values) = 'object'");
        PgSchema::check($t, 'new_values_object', "new_values IS NULL OR jsonb_typeof(new_values) = 'object'");
        PgSchema::index('audit_logs_auditable_index', $t, 'auditable_type, auditable_id, created_at DESC');
        PgSchema::index('audit_logs_actor_index', $t, 'actor_type, actor_id, created_at DESC');
        PgSchema::index('audit_logs_created_at_index', $t, 'created_at', using: 'BRIN');
        PgSchema::index('audit_logs_action_index', $t, 'action, created_at DESC');
        // ADR-026a: append-only, enforced by the database.
        PgSchema::immutable($t);

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->jsonb('value');
            $table->string('group', 50)->default('general');
            $table->boolean('is_public')->default(false);
            $table->string('description', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();
        });

        PgSchema::regex('settings', 'key', '^[a-z0-9_]+(\.[a-z0-9_]+)*$');
        PgSchema::index('settings_is_public_index', 'settings', 'is_public', 'is_public');
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
    }
};
