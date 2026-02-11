<?php

namespace App\Services;

use App\Models\Invoice;

class IntegrityHashService
{
    public static function generate(Invoice $invoice): string
    {
        $taxAmount = $invoice->items ? $invoice->items->sum('tax') : 0;

        $data = implode('|', [
            $invoice->invoice_number,
            $invoice->total_amount,
            $taxAmount,
            $invoice->company_id,
            $invoice->created_at->toIso8601String(),
        ]);

        return hash('sha256', $data);
    }

    public static function verify(Invoice $invoice): bool
    {
        if (!$invoice->integrity_hash) {
            return false;
        }

        $invoice->load('items');
        return $invoice->integrity_hash === self::generate($invoice);
    }
}
