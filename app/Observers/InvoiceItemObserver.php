<?php

namespace App\Observers;

use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceItemObserver
{
    /**
     * After every invoice item is saved, silently learn the
     * (hs_code + schedule_type + tax_rate + sro + sale_type) combination
     * into hs_usage_patterns so future invoices get auto-suggestions.
     *
     * Pure, additive, never throws — failures swallowed to never break invoicing.
     */
    public function created(InvoiceItem $item): void
    {
        $this->record($item);
    }

    public function updated(InvoiceItem $item): void
    {
        if ($item->wasChanged(['hs_code', 'schedule_type', 'tax_rate', 'sro_schedule_no', 'sale_type'])) {
            $this->record($item);
        }
    }

    private function record(InvoiceItem $item): void
    {
        try {
            $hs = trim((string) ($item->hs_code ?? ''));
            if ($hs === '' || strlen($hs) < 4) return;

            $schedule = $item->schedule_type ?: null;
            $rate     = is_numeric($item->tax_rate) ? round((float) $item->tax_rate, 2) : null;
            $sro      = $item->sro_schedule_no ?: null;
            $sr       = $item->serial_no ?: null;
            $sale     = $item->sale_type ?: null;

            // Match key: hs_code + schedule_type + tax_rate (one row per combination)
            $existing = DB::table('hs_usage_patterns')
                ->where('hs_code', $hs)
                ->where(function ($q) use ($schedule) {
                    $schedule === null ? $q->whereNull('schedule_type') : $q->where('schedule_type', $schedule);
                })
                ->where(function ($q) use ($rate) {
                    $rate === null ? $q->whereNull('tax_rate') : $q->where('tax_rate', $rate);
                })
                ->first();

            $now = now();

            if ($existing) {
                $newCount      = (int) $existing->success_count + 1;
                $newConfidence = min(99.00, 30.00 + ($newCount * 7));

                DB::table('hs_usage_patterns')->where('id', $existing->id)->update([
                    'success_count'      => $newCount,
                    'confidence_score'   => $newConfidence,
                    'sro_schedule_no'    => $sro    ?: $existing->sro_schedule_no,
                    'sro_item_serial_no' => $sr     ?: $existing->sro_item_serial_no,
                    'sale_type'          => $sale   ?: $existing->sale_type,
                    'last_used_at'       => $now,
                    'updated_at'         => $now,
                ]);
            } else {
                DB::table('hs_usage_patterns')->insert([
                    'hs_code'            => $hs,
                    'schedule_type'      => $schedule,
                    'tax_rate'           => $rate,
                    'sro_schedule_no'    => $sro,
                    'sro_item_serial_no' => $sr,
                    'sale_type'          => $sale,
                    'success_count'      => 1,
                    'rejection_count'    => 0,
                    'confidence_score'   => 30.00,
                    'admin_status'       => 'pending',
                    'last_used_at'       => $now,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('InvoiceItemObserver auto-learn failed: '.$e->getMessage(), [
                'hs_code'  => $item->hs_code ?? null,
                'item_id'  => $item->id ?? null,
            ]);
        }
    }
}
