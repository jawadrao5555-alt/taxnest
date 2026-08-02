<?php

namespace App\Helpers;

/**
 * Database compatibility helper — keeps the app dual-compatible
 * between PostgreSQL (default) and MySQL 8+.
 *
 * Always use these helpers inside DB::raw / selectRaw / whereRaw / groupByRaw
 * instead of writing PG-specific (or MySQL-specific) SQL directly.
 */
class DbCompat
{
    public static function isMySQL(): bool
    {
        return config('database.default') === 'mysql';
    }

    public static function isPgSQL(): bool
    {
        return config('database.default') === 'pgsql';
    }

    /** sqlite is only used by the automated test suite (:memory:). */
    public static function isSqlite(): bool
    {
        return config('database.default') === 'sqlite';
    }

    /** Case-insensitive LIKE operator. */
    public static function like(): string
    {
        return self::isMySQL() ? 'like' : 'ilike';
    }

    /**
     * Format a date column.
     * Pass PG-style format ('YYYY-MM', 'YYYY', 'YYYY-MM-DD'); we map to MySQL automatically.
     */
    public static function dateFormat(string $column, string $pgFormat): string
    {
        if (self::isMySQL()) {
            $map = [
                'YYYY-MM'    => '%Y-%m',
                'YYYY'       => '%Y',
                'YYYY-MM-DD' => '%Y-%m-%d',
                'YYYY-MM-DD HH24:MI' => '%Y-%m-%d %H:%i',
                'HH24'       => '%H',
                'DD'         => '%d',
            ];
            $mysqlFormat = $map[$pgFormat] ?? '%Y-%m-%d';
            return "DATE_FORMAT({$column}, '{$mysqlFormat}')";
        }
        if (self::isSqlite()) {
            // Test-suite only (sqlite :memory:) — strftime shares MySQL's tokens
            // for the formats we use, except %i (minutes) → %M.
            $map = [
                'YYYY-MM'    => '%Y-%m',
                'YYYY'       => '%Y',
                'YYYY-MM-DD' => '%Y-%m-%d',
                'YYYY-MM-DD HH24:MI' => '%Y-%m-%d %H:%M',
                'HH24'       => '%H',
                'DD'         => '%d',
            ];
            $fmt = $map[$pgFormat] ?? '%Y-%m-%d';
            return "strftime('{$fmt}', {$column})";
        }
        return "TO_CHAR({$column}::date, '{$pgFormat}')";
    }

    public static function extractYear(string $column): string
    {
        return self::isMySQL() ? "YEAR({$column})" : "EXTRACT(YEAR FROM {$column}::date)";
    }

    public static function extractMonth(string $column): string
    {
        return self::isMySQL() ? "MONTH({$column})" : "EXTRACT(MONTH FROM {$column}::date)";
    }

    public static function extractDay(string $column): string
    {
        return self::isMySQL() ? "DAY({$column})" : "EXTRACT(DAY FROM {$column}::date)";
    }

    public static function extractHour(string $column): string
    {
        return self::isMySQL() ? "HOUR({$column})" : "EXTRACT(HOUR FROM {$column})";
    }

    /** DATE(x) — works the same in both DBs, but exposed for symmetry. */
    public static function dateOnly(string $column): string
    {
        return "DATE({$column})";
    }

    /**
     * Cast a column to a target type.
     * $type is one of: 'text', 'int', 'float', 'numeric', 'date'
     */
    public static function cast(string $column, string $type): string
    {
        $type = strtolower($type);
        if (self::isMySQL()) {
            $map = [
                'text'    => 'CHAR',
                'int'     => 'SIGNED',
                'integer' => 'SIGNED',
                'float'   => 'DECIMAL(20,6)',
                'numeric' => 'DECIMAL(20,6)',
                'date'    => 'DATE',
                'datetime'=> 'DATETIME',
            ];
            $mysqlType = $map[$type] ?? 'CHAR';
            return "CAST({$column} AS {$mysqlType})";
        }
        // Postgres native casts
        $pgType = $type === 'int' ? 'integer' : $type;
        return "CAST({$column} AS {$pgType})";
    }

    /** Cast to float / double precision — common for AVG calculations. */
    public static function castFloat(string $column): string
    {
        return self::cast($column, 'float');
    }

    /** SUBSTRING — works in both DBs with this syntax. Exposed for clarity. */
    public static function substring(string $column, int $start, int $length): string
    {
        return "SUBSTRING({$column}, {$start}, {$length})";
    }

    /**
     * Boolean literal for use inside CASE WHEN / WHERE.
     * MySQL accepts both 1/0 and TRUE/FALSE; PG strict on TRUE/FALSE.
     * Returning '1'/'0' is universally safe inside CASE WHEN comparisons
     * only when the column is boolean — for safety, prefer column-aware queries.
     */
    public static function boolTrue(): string
    {
        return self::isMySQL() ? '1' : 'TRUE';
    }

    public static function boolFalse(): string
    {
        return self::isMySQL() ? '0' : 'FALSE';
    }

    /**
     * Extract a value from a JSON column.
     * Returns SQL fragment that gives the unquoted scalar string.
     */
    public static function jsonExtract(string $column, string $key): string
    {
        if (self::isMySQL()) {
            return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}'))";
        }
        return "({$column}->>'{$key}')::text";
    }
}
