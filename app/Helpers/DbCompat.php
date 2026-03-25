<?php

namespace App\Helpers;

class DbCompat
{
    public static function isMySQL(): bool
    {
        return config('database.default') === 'mysql';
    }

    public static function like(): string
    {
        return self::isMySQL() ? 'like' : 'ilike';
    }

    public static function dateFormat(string $column, string $pgFormat): string
    {
        if (self::isMySQL()) {
            $map = [
                'YYYY-MM' => '%Y-%m',
                'YYYY' => '%Y',
                'YYYY-MM-DD' => '%Y-%m-%d',
            ];
            $mysqlFormat = $map[$pgFormat] ?? '%Y-%m-%d';
            return "DATE_FORMAT({$column}, '{$mysqlFormat}')";
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

    public static function jsonExtract(string $column, string $key): string
    {
        if (self::isMySQL()) {
            return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}'))";
        }
        return "({$column}->>'{$key}')::text";
    }
}
