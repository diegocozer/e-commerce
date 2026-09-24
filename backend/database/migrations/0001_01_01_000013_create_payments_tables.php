<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE.md §3.7 — payments, webhook events, immutable transactions and refunds. */
return new class extends Migration
{
    private const array PROVIDERS = ['sandbox', 'mercadopago'];

    private const array PAYMENT_STATUSES = ['pending', 'approved', 'failed', 'expired', 'cancelled', 'refunded', 'partially_refunded'];

    public function up(): void
    {
        $this->createPayments();
        $this->createWebhookEvents();
        $this->createTransactions();
        $this->createRefunds();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payments');
    }

    private function createPayments(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('method', 20);
            $table->string('status', 20)->default('pending');
            $table->bigInteger('amount_cents');
            $table->bigInteger('refunded_cents')->default(0);
            $table->string('external_id', 191)->nullable();
            $table->uuid('idempotency_key')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->text('pix_copy_paste')->nullable();
            $table->text('pix_qr_code_base64')->nullable();
            $table->string('boleto_url', 500)->nullable();
            $table->string('boleto_barcode', 60)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestampsTz();

            $table->index('order_id');
        });

        $t = 'payments';
        PgSchema::enum($t, 'provider', self::PROVIDERS);
        PgSchema::enum($t, 'method', ['pix', 'credit_card', 'boleto', 'invoice']);
        PgSchema::enum($t, 'status', self::PAYMENT_STATUSES);
        PgSchema::positive($t, 'amount_cents');
        PgSchema::nonNegative($t, 'refunded_cents');
        PgSchema::check($t, 'refunded_lte_amount', 'refunded_cents <= amount_cents');
        PgSchema::check($t, 'paid_fields', "status NOT IN ('approved','refunded','partially_refunded') OR paid_at IS NOT NULL");
        PgSchema::check($t, 'refunded_fully', "status <> 'refunded' OR refunded_cents = amount_cents");
        PgSchema::uniqueWhere('payments_provider_external_unique', $t, 'provider, external_id', 'external_id IS NOT NULL');
        PgSchema::uniqueWhere('payments_order_active_unique', $t, 'order_id', "status IN ('pending','approved')");
        PgSchema::index('payments_pending_expiry_index', $t, 'expires_at', "status = 'pending'");
    }

    private function createWebhookEvents(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('external_id', 191);
            $table->string('event_type', 100);
            $table->jsonb('payload');
            $table->boolean('signature_valid');
            $table->string('status', 10)->default('received');
            $table->smallInteger('attempts')->default(0);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->timestampTz('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->ipAddress('received_ip')->nullable();
            $table->timestampTz('created_at')->default(DB::raw('now()'));
            $table->timestampTz('updated_at')->nullable();

            $table->index('payment_id');
        });

        $t = 'webhook_events';
        PgSchema::enum($t, 'provider', self::PROVIDERS);
        PgSchema::enum($t, 'status', ['received', 'processed', 'ignored', 'failed']);
        PgSchema::nonNegative($t, 'attempts');
        PgSchema::check($t, 'payload_object', "jsonb_typeof(payload) = 'object'");
        // DB-12: only events with a valid signature take part in the dedupe.
        PgSchema::uniqueWhere('webhook_events_provider_external_unique', $t, 'provider, external_id', 'signature_valid');
        PgSchema::index('webhook_events_status_created_index', $t, 'status, created_at', "status IN ('received','failed')");
    }

    private function createTransactions(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('type', 10);
            $table->string('status_before', 20)->nullable();
            $table->string('status_after', 20);
            $table->bigInteger('amount_cents')->default(0);
            $table->string('external_id', 191)->nullable();
            $table->foreignId('webhook_event_id')->nullable()->constrained('webhook_events')->restrictOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->default(DB::raw('now()'));

            $table->index(['payment_id', 'created_at'], 'payment_transactions_payment_created_index');
        });

        $t = 'payment_transactions';
        PgSchema::enum($t, 'type', ['create', 'approve', 'fail', 'refund', 'expire', 'cancel', 'sync']);
        PgSchema::enum($t, 'status_before', self::PAYMENT_STATUSES);
        PgSchema::enum($t, 'status_after', self::PAYMENT_STATUSES);
        PgSchema::nonNegative($t, 'amount_cents');
        PgSchema::check($t, 'payload_object', "payload IS NULL OR jsonb_typeof(payload) = 'object'");
        PgSchema::immutable($t);
    }

    private function createRefunds(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('status', 20)->default('pending');
            $table->string('reason', 500);
            $table->uuid('idempotency_key')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->string('external_id', 191)->nullable();
            $table->string('requested_by_type', 10);
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('failure_reason', 255)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index('payment_id');
        });

        $t = 'payment_refunds';
        PgSchema::positive($t, 'amount_cents');
        PgSchema::enum($t, 'status', ['pending', 'processing', 'succeeded', 'failed']);
        PgSchema::enum($t, 'requested_by_type', ['admin', 'system']);
        PgSchema::check($t, 'admin_requester', "(requested_by_type = 'admin') = (admin_user_id IS NOT NULL)");
        PgSchema::check($t, 'completed_fields', "status NOT IN ('succeeded','failed') OR completed_at IS NOT NULL");
        PgSchema::uniqueWhere('payment_refunds_payment_active_unique', $t, 'payment_id', "status IN ('pending','processing')");
    }
};
