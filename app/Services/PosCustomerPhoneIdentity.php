<?php

namespace App\Services;

final class PosCustomerPhoneIdentity
{
    public static function canonical(?string $phone): string
    {
        return PkPhone::normalize($phone) ?? preg_replace('/\D+/', '', (string) $phone);
    }

    /** Exact compatible identities; a foreign number sharing a suffix is different. */
    public static function variants(string $phone): array
    {
        $canonical = self::canonical($phone);
        $digits = preg_replace('/\D+/', '', $phone);
        $variants = [$digits, $canonical, '00'.$canonical];
        if (str_starts_with($canonical, '92')) {
            $national = substr($canonical, 2);
            $variants = array_merge($variants, ['0'.$national, $national, '920'.$national, '00920'.$national]);
        }
        return array_values(array_unique(array_filter($variants, fn ($v) => $v !== '')));
    }

    public static function searchDigits(string $term): string
    {
        return preg_match('/^[0-9+().\s-]+$/uD', trim($term)) ? preg_replace('/\D+/', '', $term) : '';
    }

    /** Bound SQL, portable across the supported MySQL/MariaDB/SQLite drivers. */
    public static function constrain($query, string $column, string $term, bool $partial = true): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?$/D', $column)) throw new \InvalidArgumentException('Invalid phone column');
        $expression = "COALESCE($column,'')";
        foreach (['-', ' ', '(', ')', '+', '.', "\t", "\n", "\r"] as $separator) {
            // Column names are internal allowlisted identifiers; separators are constants.
            $expression = "REPLACE($expression,'$separator','')";
        }
        $digits = self::searchDigits($term);
        if ($digits === '') { $query->whereRaw('1=0'); return; }
        if (PkPhone::normalize($term) !== null) {
            $variants = self::variants($term);
            $query->whereRaw($expression.' IN ('.implode(',', array_fill(0, count($variants), '?')).')', $variants);
        } elseif ($partial && strlen($digits) >= 3) {
            $query->whereRaw($expression.' LIKE ?', ['%'.$digits.'%']);
        } else {
            $query->whereRaw($expression.' = ?', [$digits]);
        }
    }

    /** Match the existing customer-create mutex for equivalent typed formats. */
    public static function creationLockKeys(int|string $companyId, string $phone): array
    {
        $variants = self::variants($phone);
        $keys = array_map(fn ($number) => 'poscust:'.$companyId.':'.$number, array_unique($variants));
        sort($keys, SORT_STRING);
        return $keys;
    }
}
