<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DATABASE.md §0.1 — extensions, full-text configuration and helper functions.
 * Written to be idempotent because `migrate:fresh` drops tables but not
 * extensions, functions or text search configurations.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_ts_config WHERE cfgname = 'pt_unaccent') THEN
                    CREATE TEXT SEARCH CONFIGURATION public.pt_unaccent (COPY = pg_catalog.portuguese);
                    ALTER TEXT SEARCH CONFIGURATION public.pt_unaccent
                        ALTER MAPPING FOR hword, hword_part, word WITH unaccent, portuguese_stem;
                END IF;
            END
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.f_unaccent(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.forbid_mutation() RETURNS trigger LANGUAGE plpgsql AS
            $$ BEGIN RAISE EXCEPTION '% is append-only', TG_TABLE_NAME; END $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.forbid_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS public.f_unaccent(text) CASCADE');
        DB::statement('DROP TEXT SEARCH CONFIGURATION IF EXISTS public.pt_unaccent CASCADE');
        DB::statement('DROP EXTENSION IF EXISTS btree_gist');
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
        DB::statement('DROP EXTENSION IF EXISTS unaccent');
    }
};
