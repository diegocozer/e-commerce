<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL specific DDL helpers used by migrations (DATABASE.md §1).
 *
 * The Laravel schema builder has no first-class support for CHECK constraints,
 * partial unique indexes, NULLS NOT DISTINCT, EXCLUDE constraints or triggers,
 * so migrations use these helpers (thin wrappers over DB::statement) to keep
 * constraint names consistent with the naming convention of DATABASE.md §1.6.
 */
final class PgSchema
{
    /** @var list<string> Brazilian states (`<UF_LIST>` in DATABASE.md §1.2). */
    public const array UF_LIST = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public const string SLUG_REGEX = '^[a-z0-9]+(-[a-z0-9]+)*$';

    public const string POSTAL_CODE_REGEX = '^[0-9]{8}$';

    public const string IBGE_CODE_REGEX = '^[0-9]{7}$';

    public const string PHONE_REGEX = '^[0-9]{10,13}$';

    /** Adds `CONSTRAINT {table}_{rule}_check CHECK ({expression})`. */
    public static function check(string $table, string $rule, string $expression): void
    {
        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
            $table,
            "{$table}_{$rule}_check",
            $expression,
        ));
    }

    /**
     * Enum as varchar + CHECK (DB-03). Constraint name: `{table}_{column}_check`.
     *
     * @param  list<string>  $values
     */
    public static function enum(string $table, string $column, array $values): void
    {
        self::check($table, $column, sprintf('%s IN (%s)', self::quoteIdentifier($column), self::quoteList($values)));
    }

    public static function uf(string $table, string $column): void
    {
        self::enum($table, $column, self::UF_LIST);
    }

    public static function regex(string $table, string $column, string $regex): void
    {
        self::check($table, $column.'_format', sprintf("%s ~ '%s'", self::quoteIdentifier($column), $regex));
    }

    public static function nonNegative(string $table, string ...$columns): void
    {
        foreach ($columns as $column) {
            self::check($table, $column.'_non_negative', sprintf('%s >= 0', self::quoteIdentifier($column)));
        }
    }

    public static function positive(string $table, string ...$columns): void
    {
        foreach ($columns as $column) {
            self::check($table, $column.'_positive', sprintf('%s > 0', self::quoteIdentifier($column)));
        }
    }

    /** `CREATE UNIQUE INDEX {name} ON {table} ({columns}) WHERE {where}`. */
    public static function uniqueWhere(string $name, string $table, string $columns, string $where): void
    {
        DB::statement(sprintf('CREATE UNIQUE INDEX %s ON %s (%s) WHERE %s', $name, $table, $columns, $where));
    }

    /** `CREATE INDEX {name} ON {table} [USING method] ({columns}) [WHERE ...]`. */
    public static function index(string $name, string $table, string $columns, ?string $where = null, ?string $using = null): void
    {
        DB::statement(sprintf(
            'CREATE INDEX %s ON %s %s(%s)%s',
            $name,
            $table,
            $using !== null ? "USING {$using} " : '',
            $columns,
            $where !== null ? " WHERE {$where}" : '',
        ));
    }

    /** `ALTER TABLE ... ADD CONSTRAINT {name} UNIQUE NULLS NOT DISTINCT (...)` (PG 15+). */
    public static function uniqueNullsNotDistinct(string $name, string $table, string $columns): void
    {
        DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s UNIQUE NULLS NOT DISTINCT (%s)', $table, $name, $columns));
    }

    /** Append-only table: blocks UPDATE and DELETE (function created by the extensions migration). */
    public static function immutable(string $table): void
    {
        DB::statement(sprintf(
            'CREATE TRIGGER %s_immutable BEFORE UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION public.forbid_mutation()',
            $table,
            $table,
        ));
    }

    /** @param  list<string>  $values */
    public static function quoteList(array $values): string
    {
        return implode(',', array_map(static fn (string $v): string => "'".str_replace("'", "''", $v)."'", $values));
    }

    private static function quoteIdentifier(string $column): string
    {
        return '"'.str_replace('"', '""', $column).'"';
    }
}
