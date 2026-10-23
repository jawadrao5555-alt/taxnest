<?php

namespace App\Support;

/** Presentation only: never clips item names or changes receipt money. */
final class PosReceiptTypography
{
    public static function from(array $style): array
    {
        return [
            'font_family' => in_array($style['font_family'] ?? '', ['legacy', 'sans', 'mono'], true) ? $style['font_family'] : 'legacy',
            'item_text' => in_array($style['item_text'] ?? '', ['legacy', 'compact', 'large'], true) ? $style['item_text'] : 'legacy',
            'wide_names' => filter_var($style['wide_names'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public static function css(array $style, string $paper): string
    {
        $s = self::from($style);
        $css = '';
        if ($s['font_family'] !== 'legacy') {
            $font = $s['font_family'] === 'mono' ? '"Courier New",Courier,monospace' : 'Arial,Helvetica,sans-serif';
            $css .= 'body,body td,body th,body p,body span{font-family:'.$font.' !important;}';
        }
        if ($s['item_text'] !== 'legacy') {
            $px = $s['item_text'] === 'compact' ? ($paper === '58mm' ? 9 : 10) : ($paper === '58mm' ? 12 : 14);
            $css .= '.items-table td{font-size:'.$px.'px !important;}';
        }
        if ($s['wide_names']) {
            $css .= '.items-table .col-item{width:52%;}.items-table .col-qty{width:12%;}.items-table .col-rate{width:16%;}.items-table .col-total{width:20%;}';
        }
        // Long names stay complete and belong to one visually separated item row.
        return $css;
    }
}
