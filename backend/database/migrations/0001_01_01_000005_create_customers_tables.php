<?php

use App\Shared\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE.md §3.4 — companies, customers (guard `customer`), reset tokens and addresses. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name', 200);
            $table->string('trade_name', 200)->nullable();
            $table->char('cnpj', 14)->unique();
            $table->string('state_registration', 20)->nullable();
            $table->boolean('state_registration_exempt')->default(false);
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->restrictOnDelete();
            $table->timestampsTz();
        });

        PgSchema::regex('companies', 'cnpj', '^[0-9A-Z]{12}[0-9]{2}$');
        PgSchema::regex('companies', 'state_registration', '^[0-9]{2,14}$');
        PgSchema::check('companies', 'state_registration_xor_exempt', 'state_registration_exempt <> (state_registration IS NOT NULL)');

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->string('type', 10);
            $table->string('name', 150);
            $table->string('email', 255);
            $table->string('password', 255);
            $table->char('cpf', 11)->nullable();
            $table->string('phone', 20)->nullable();
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->restrictOnDelete();
            $table->timestampTz('email_verified_at')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestampTz('marketing_opt_in_at')->nullable();
            $table->string('terms_version', 20);
            $table->timestampTz('terms_accepted_at');
            $table->ipAddress('terms_accepted_ip')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('anonymized_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('company_id');
            $table->index('created_at');
        });

        PgSchema::enum('customers', 'type', ['individual', 'company']);
        PgSchema::check('customers', 'email_lowercase', 'email = lower(email)');
        PgSchema::regex('customers', 'cpf', '^[0-9]{11}$');
        PgSchema::regex('customers', 'phone', PgSchema::PHONE_REGEX);
        PgSchema::check('customers', 'company_matches_type', "(type = 'company') = (company_id IS NOT NULL)");
        PgSchema::uniqueWhere('customers_email_active_unique', 'customers', 'email', 'deleted_at IS NULL');
        PgSchema::uniqueWhere('customers_cpf_active_unique', 'customers', 'cpf', 'cpf IS NOT NULL AND deleted_at IS NULL');
        PgSchema::index('customers_name_trgm_index', 'customers', 'name gin_trgm_ops', using: 'GIN');
        PgSchema::index('customers_email_trgm_index', 'customers', 'email gin_trgm_ops', using: 'GIN');

        Schema::create('customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 255)->primary();
            $table->string('token', 255);
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            // ADR-028: public id, customer routes use /me/addresses/{uuid}.
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label', 50)->nullable();
            $table->string('recipient_name', 150);
            $table->string('phone', 20)->nullable();
            $table->char('postal_code', 8);
            $table->string('street', 200);
            $table->string('number', 20);
            $table->string('complement', 100)->nullable();
            $table->string('district', 100);
            $table->string('city', 100);
            $table->char('state', 2);
            $table->char('city_ibge_code', 7)->nullable();
            $table->string('reference', 200)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        PgSchema::regex('customer_addresses', 'postal_code', PgSchema::POSTAL_CODE_REGEX);
        PgSchema::regex('customer_addresses', 'city_ibge_code', PgSchema::IBGE_CODE_REGEX);
        PgSchema::regex('customer_addresses', 'phone', PgSchema::PHONE_REGEX);
        PgSchema::uf('customer_addresses', 'state');
        PgSchema::uniqueWhere('customer_addresses_default_unique', 'customer_addresses', 'customer_id', 'is_default AND deleted_at IS NULL');
        PgSchema::index('customer_addresses_customer_id_index', 'customer_addresses', 'customer_id', 'deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_password_reset_tokens');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('companies');
    }
};
