<?php

namespace App\Services;

use App\Models\Invoice;

class IntegrityHashService
{
    public static function generate(Invoice $invoice): string
    {
        $invoice->loadMissing('items');

        $items = $invoice->items
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'hs_code' => (string) $item->hs_code,
                'schedule_type' => (string) $item->schedule_type,
                'tax_rate' => self::amount($item->tax_rate),
                'sro_schedule_no' => (string) $item->sro_schedule_no,
                'serial_no' => (string) $item->serial_no,
                'mrp' => self::amount($item->mrp),
                'description' => (string) $item->description,
                'quantity' => self::amount($item->quantity),
                'price' => self::amount($item->price),
                'tax' => self::amount($item->tax),
            ])
            ->sortBy('id')
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'version' => 2,
            'company_id' => (int) $invoice->company_id,
            'branch_id' => $invoice->branch_id ? (int) $invoice->branch_id : null,
            'invoice_number' => (string) $invoice->invoice_number,
            'internal_invoice_number' => (string) $invoice->internal_invoice_number,
            'document_type' => (string) $invoice->document_type,
            'reference_invoice_number' => (string) $invoice->reference_invoice_number,
            'invoice_date' => (string) $invoice->invoice_date,
            'buyer_ntn' => (string) $invoice->buyer_ntn,
            'buyer_cnic' => (string) $invoice->buyer_cnic,
            'buyer_registration_type' => (string) $invoice->buyer_registration_type,
            'total_amount' => self::amount($invoice->total_amount),
            'total_value_excluding_st' => self::amount($invoice->total_value_excluding_st),
            'total_sales_tax' => self::amount($invoice->total_sales_tax),
            'fbr_invoice_number' => (string) $invoice->fbr_invoice_number,
            'fiscal_payload_hash' => (string) $invoice->fiscal_payload_hash,
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function amount($value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
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
