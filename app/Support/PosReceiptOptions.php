<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Http\Request;

/** Optional presentation overrides. Absent/inherit never changes a legacy design. */
final class PosReceiptOptions
{
    public const VISIBILITY = [
        'show_logo' => 'Logo', 'show_business_name' => 'Business name',
        'show_address' => 'Address', 'show_mobile' => 'Phone / mobile',
        'show_email' => 'Email', 'show_ntn' => 'NTN',
        'show_business_activity' => 'Business activity', 'show_website' => 'Website',
        'show_date' => 'Bill date and time', 'show_terminal' => 'Terminal',
        'show_payment' => 'Payment method', 'show_cashier' => 'Cashier',
        'show_closed_by' => 'Closed by', 'show_waiter' => 'Waiter',
        'show_table' => 'Table', 'show_floor' => 'Floor beside table',
        'show_order_type' => 'Dine-in / takeaway / delivery',
        'show_order_match' => 'Extra order matching token / code',
        'show_item_serial' => 'Item row numbers', 'show_quantity' => 'Quantity column',
        'show_rate' => 'Rate column', 'show_item_notes' => 'Item comments',
        'show_deal_components' => 'Deal contents', 'show_bill_notes' => 'Bill notes',
        'show_subtotal' => 'Subtotal / menu total line',
        'show_menu_qr' => 'Extra bill / menu QR (not fiscal QR)',
        'show_qr_caption' => 'Extra QR caption', 'show_verify_line' => 'Fiscal QR caption',
        'show_footer' => 'Thank-you / footer text', 'show_developed_by' => 'Developed by line',
        'show_print_time' => 'Printed-at timestamp',
    ];

    public const CHOICES = [
        'design' => PosPrintDesigns::RECEIPTS,
        'kot_design' => PosPrintDesigns::KOTS,
        'logo_position' => ['inherit' => 'Keep current', 'center' => 'Centre', 'side' => 'Beside business name'],
        'logo_size' => ['inherit' => 'Keep current', 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
        'extra_qr_size' => ['inherit' => 'Keep current', 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
        'font_family' => ['inherit' => 'Keep current', 'sans' => 'Arial / sans', 'mono' => 'Monospace'],
        'item_text' => ['inherit' => 'Keep current', 'compact' => 'Compact', 'large' => 'Large'],
        'item_bold' => ['inherit' => 'Keep current', 'normal' => 'Normal', 'bold' => 'Bold'],
        'heading_size' => ['inherit' => 'Keep current', 'compact' => 'Compact', 'large' => 'Large'],
        'heading_bold' => ['inherit' => 'Keep current', 'normal' => 'Normal', 'bold' => 'Bold'],
        'total_size' => ['inherit' => 'Keep current', 'compact' => 'Compact', 'large' => 'Large'],
        'total_bold' => ['inherit' => 'Keep current', 'normal' => 'Normal', 'bold' => 'Bold'],
        'spacing' => ['inherit' => 'Keep current', 'compact' => 'Compact', 'roomy' => 'Roomy'],
        'date_format' => ['inherit' => 'Keep current', '24h' => 'Day/month/year, 24-hour', 'iso' => 'Year-month-day, 24-hour'],
    ];

    public const LABELS = [
        'design' => 'Receipt design', 'kot_design' => 'Kitchen ticket design',
        'logo_position' => 'Logo position', 'logo_size' => 'Logo size', 'extra_qr_size' => 'Extra QR size',
        'font_family' => 'Font', 'item_text' => 'Item text size', 'item_bold' => 'Item text weight',
        'heading_size' => 'Heading size', 'heading_bold' => 'Heading weight',
        'total_size' => 'TOTAL size', 'total_bold' => 'TOTAL weight', 'spacing' => 'Spacing', 'date_format' => 'Bill date format',
    ];

    public static function saved(Company $company, string $stream): array
    {
        $all = $company->invoice_display_prefs;
        $raw = is_array($all) ? ($all['receipt_options'][$stream] ?? []) : [];

        return is_array($raw) ? $raw : [];
    }

    public static function forBill(Company $company, $bill): array
    {
        // Exactly the same stream predicate as the existing display preferences.
        $isPra = ($bill->invoice_mode ?? 'pra') === 'pra' && $bill->pra_status !== null && $bill->pra_status !== 'exempt_internal';
        $stream = $isPra ? 'pra' : 'local';

        return self::saved($company, $stream);
    }

    public static function visible(array $options, string $key, bool $legacy = true): bool
    {
        return match ($options[$key] ?? 'inherit') {
            'show' => true, 'hide' => false, default => $legacy
        };
    }

    public static function rules(): array
    {
        $rules = [];
        foreach (['pra', 'local'] as $stream) {
            $prefix = 'receipt_options_'.$stream;
            $rules[$prefix] = 'sometimes|array:'.implode(',', array_merge(array_keys(self::VISIBILITY), array_keys(self::CHOICES), ['extra_note']));
            foreach (self::VISIBILITY as $key => $_) {
                $rules[$prefix.'.'.$key] = 'sometimes|required|in:inherit,show,hide';
            }
            foreach (self::CHOICES as $key => $choices) {
                $rules[$prefix.'.'.$key] = 'sometimes|required|in:'.implode(',', array_keys($choices));
            }
            $rules[$prefix.'.extra_note'] = 'sometimes|nullable|string|max:150';
        }

        return $rules;
    }

    public static function merge(array $prefs, Request $request): array
    {
        foreach (['pra', 'local'] as $stream) {
            $input = $request->input('receipt_options_'.$stream);
            if (! is_array($input)) {
                continue;
            }
            $current = is_array($prefs['receipt_options'][$stream] ?? null) ? $prefs['receipt_options'][$stream] : [];
            foreach (array_merge(array_keys(self::VISIBILITY), array_keys(self::CHOICES)) as $key) {
                if (! array_key_exists($key, $input)) {
                    continue;
                } // stale forms preserve newer controls
                if ($input[$key] === 'inherit') {
                    unset($current[$key]);
                } else {
                    $current[$key] = $input[$key];
                }
            }
            if (array_key_exists('extra_note', $input)) {
                $current['extra_note'] = trim((string) $input['extra_note']);
            }
            $prefs['receipt_options'][$stream] = $current;
        }

        return $prefs;
    }

    public static function dateFormat(array $options): string
    {
        return match ($options['date_format'] ?? '') {
            '24h' => 'd/m/Y H:i', 'iso' => 'Y-m-d H:i', default => 'd/m/Y h:i A'
        };
    }

    public static function css(array $options, string $paper): string
    {
        $small = $paper === '58mm';
        $typography = [];
        foreach (['font_family', 'item_text'] as $key) {
            if (in_array($options[$key] ?? '', array_keys(self::CHOICES[$key]), true) && $options[$key] !== 'inherit') {
                $typography[$key] = $options[$key];
            }
        }
        $css = PosPrintDesigns::receiptCss($options, $paper).PosReceiptTypography::css($typography, $paper);
        foreach (['item_bold' => '.items-table td,.items-table td *', 'heading_bold' => '.header h1,.invoice-numbers>strong', 'total_bold' => '.grand-total td'] as $key => $selector) {
            if (in_array($options[$key] ?? '', ['normal', 'bold'], true)) {
                $css .= $selector.'{font-weight:'.($options[$key] === 'bold' ? '700' : '400').' !important;}';
            }
        }
        foreach (['heading_size' => '.header h1,.invoice-numbers>strong', 'total_size' => '.grand-total td'] as $key => $selector) {
            if (in_array($options[$key] ?? '', ['compact', 'large'], true)) {
                $css .= $selector.'{font-size:'.($options[$key] === 'compact' ? ($small ? 12 : 14) : ($small ? 17 : 20)).'px !important;}';
            }
        }
        if (in_array($options['logo_size'] ?? '', ['small', 'medium', 'large'], true)) {
            $mm = ['small' => 14, 'medium' => 22, 'large' => ($small ? 28 : 32)][$options['logo_size']];
            $css .= '.header img{width:'.$mm.'mm !important;max-width:100% !important;height:auto !important;max-height:'.$mm.'mm !important;object-fit:contain;}';
        }
        if (in_array($options['extra_qr_size'] ?? '', ['small', 'medium', 'large'], true)) {
            $px = ['small' => 64, 'medium' => 84, 'large' => 100][$options['extra_qr_size']];
            $css .= '.receipt-extra-qr img{width:'.$px.'px !important;height:'.$px.'px !important;}';
        }
        if (in_array($options['spacing'] ?? '', ['compact', 'roomy'], true)) {
            $pad = $options['spacing'] === 'compact' ? 1 : 4;
            $css .= '.items-table td,.info-table td{padding-top:'.$pad.'px !important;padding-bottom:'.$pad.'px !important;}';
        }

        return $css;
    }
}
