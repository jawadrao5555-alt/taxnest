<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FbrPosInvoiceSeries
{
    public static function issue(int $companyId, string $prefix): string
    {
        if (!preg_match('/^(?:(?:FPOS|FLOCAL)-[0-9]{4}|RET-[0-9]{6})-$/D', $prefix)) {
            throw new \InvalidArgumentException('Unsupported FBR POS invoice series.');
        }
        return DB::transaction(function () use ($companyId, $prefix) {
            // The company row exists before its first bill or counter. It
            // serializes first allocation as well as later counter updates.
            if (!DB::table('companies')->where('id', $companyId)->lockForUpdate()->value('id')) {
                throw new \RuntimeException('Invoice company not found.');
            }
            $last = self::recordedMaximum($companyId, $prefix);
            if (Schema::hasTable('fbr_pos_invoice_sequences')) {
                $counter = DB::table('fbr_pos_invoice_sequences')->where('company_id', $companyId)
                    ->where('prefix', $prefix)->lockForUpdate()->first();
                $last = max($last, (int) ($counter->last_number ?? 0));
                DB::table('fbr_pos_invoice_sequences')->updateOrInsert(
                    ['company_id' => $companyId, 'prefix' => $prefix],
                    ['last_number' => $last + 1, 'updated_at' => now()]
                );
            }
            return $prefix.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
        }, 5);
    }

    private static function recordedMaximum(int $companyId, string $prefix): int
    {
        $maximum = 0;
        foreach (DB::table('fbr_pos_transactions')->where('company_id', $companyId)
            ->where('invoice_number', 'like', $prefix.'%')->pluck('invoice_number') as $number) {
            if (preg_match('/^'.preg_quote($prefix, '/').'([0-9]+)$/D', $number, $match)) {
                $maximum = max($maximum, (int) $match[1]);
            }
        }
        return $maximum;
    }
}
