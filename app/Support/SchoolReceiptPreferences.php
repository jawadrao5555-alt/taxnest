<?php
namespace App\Support;

use App\Models\Company;

/** School display preferences do not rewrite retail/PRA settings or receipt amounts. */
final class SchoolReceiptPreferences
{
    public static function for(Company $company): array
    {
        $all = $company->invoice_display_prefs;
        $saved = is_array($all) && is_array($all['school_receipt'] ?? null) ? $all['school_receipt'] : [];
        $paper = $saved['paper'] ?? match ($company->print_paper_size) {
            'thermal58' => '58mm', 'a4' => 'a4', default => '80mm',
        };
        return [
            'paper' => in_array($paper, ['58mm', '80mm', 'a4'], true) ? $paper : '80mm',
            'show_logo' => (bool) ($saved['show_logo'] ?? $company->posReceiptStyle()['show_logo']),
            'show_contact' => (bool) ($saved['show_contact'] ?? true),
            'center' => (bool) ($saved['center'] ?? true),
            'margin_mm' => max(0, min(10, (int) ($saved['margin_mm'] ?? 2))),
            'printer_label' => (string) ($saved['printer_label'] ?? ''),
        ];
    }
}
