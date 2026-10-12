<?php

namespace App\Services;

use App\Models\Invoice;

class DiApiRequestIdentity
{
    public static function fingerprint(array $input): string
    {
        $canonical = self::canonical($input);
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(array $input): array
    {
        $header = [];
        foreach (['buyer_name', 'buyer_ntn', 'buyer_cnic', 'buyer_address', 'buyer_registration_type',
            'branch_id', 'document_type', 'reference_invoice_number', 'destination_province', 'invoice_date'] as $key) {
            $header[$key] = trim((string) ($input[$key] ?? ''));
        }
        $header['mode'] = $input['mode'] ?? 'submit';
        $header['items'] = array_map(function (array $item) {
            $row = [];
            foreach (['hs_code', 'description', 'schedule_type', 'pct_code', 'sro_schedule_no', 'serial_no', 'default_uom'] as $key) {
                $row[$key] = trim((string) ($item[$key] ?? ''));
            }
            $row['schedule_type'] = $row['schedule_type'] ?: 'standard';
            foreach (['quantity', 'price', 'tax', 'tax_rate', 'mrp', 'petroleum_levy', 'further_tax', 'st_withheld_amount'] as $key) {
                $row[$key] = isset($item[$key]) && $item[$key] !== '' ? number_format((float) $item[$key], 4, '.', '') : null;
            }
            $row['st_withheld_at_source'] = !empty($item['st_withheld_at_source']);
            return $row;
        }, $input['items'] ?? []);
        return $header;
    }

    public static function matches(Invoice $invoice, array $input): bool
    {
        if ($invoice->di_api_request_hash) {
            return hash_equals((string) $invoice->di_api_request_hash, self::fingerprint($input));
        }
        // Pre-upgrade references have no request snapshot. Compare supplied fields
        // against persisted data; never silently accept a changed historic bill.
        foreach (['buyer_name', 'buyer_ntn', 'buyer_cnic', 'buyer_address', 'buyer_registration_type',
            'branch_id', 'document_type', 'reference_invoice_number', 'destination_province', 'invoice_date'] as $key) {
            if (array_key_exists($key, $input) && trim((string) $input[$key]) !== trim((string) $invoice->$key)) return false;
        }
        $invoice->loadMissing('items');
        if ($invoice->items->count() !== count($input['items'] ?? [])) return false;
        $rows = $invoice->items->sortBy('id')->values();
        foreach ($input['items'] as $index => $item) {
            foreach (self::canonical(['items' => [$item]])['items'][0] as $key => $value) {
                if (!array_key_exists($key, $item)) continue;
                $stored = $rows[$index]->$key;
                if (in_array($key, ['quantity', 'price', 'tax', 'tax_rate', 'mrp', 'petroleum_levy', 'further_tax', 'st_withheld_amount'])) {
                    if (number_format((float) $stored, 4, '.', '') !== number_format((float) $item[$key], 4, '.', '')) return false;
                } elseif ($key === 'st_withheld_at_source') {
                    if ((bool) $stored !== $value) return false;
                } elseif (trim((string) $stored) !== $value) return false;
            }
        }
        return true;
    }
}
