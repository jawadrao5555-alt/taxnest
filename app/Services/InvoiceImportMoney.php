<?php

namespace App\Services;

use InvalidArgumentException;

/** Source money is parsed as integer paisa; never rounded or guessed. */
final class InvoiceImportMoney
{
    public static function cents($value): int
    {
        $v = trim((string) $value);
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d{1,2})?$/D', $v)) {
            $v = str_replace(',', '', $v);
        }
        if (!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D', $v, $m)) {
            throw new InvalidArgumentException('Source amounts must be explicit money values with at most two decimal places.');
        }
        $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        return ($m[1] === '-' ? -1 : 1) * $cents;
    }

    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
