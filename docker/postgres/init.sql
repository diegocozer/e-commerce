-- Executed once by the postgres container on an empty data directory.
-- Extensions are also created by the first migration (idempotent).
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- Test databases (ADR-017): one per agent/runner.
CREATE DATABASE ecommerce_test OWNER ecommerce;
CREATE DATABASE ecommerce_test_a OWNER ecommerce;
CREATE DATABASE ecommerce_test_b OWNER ecommerce;
CREATE DATABASE ecommerce_test_c OWNER ecommerce;
CREATE DATABASE ecommerce_test_d OWNER ecommerce;
CREATE DATABASE ecommerce_test_e OWNER ecommerce;
CREATE DATABASE ecommerce_test_f OWNER ecommerce;
