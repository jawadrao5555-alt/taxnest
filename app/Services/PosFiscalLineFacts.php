<?php
namespace App\Services;
use Illuminate\Support\Facades\Schema;

/** Freeze the amounts already calculated at checkout; never re-tax an old line. */
final class PosFiscalLineFacts
{
    public static function fields(float $netMenu, float $tax, bool $inclusive, bool $exempt): array
    {
        if (!Schema::hasColumn('pos_transaction_items','taxable_base') || !Schema::hasColumn('pos_transaction_items','exempt_base')) return [];
        $base=round(max(0,$netMenu-($inclusive ? $tax : 0)),2);
        return ['taxable_base'=>$exempt ? 0 : $base,'exempt_base'=>$exempt ? $base : 0];
    }
}
