<?php

namespace App\Support;

/**
 * Receipt Themes (Task 712) — SINGLE SOURCE OF TRUTH.
 *
 * Named bundles over the EXISTING invoice_display_prefs['pos_style'] keys
 * ('bold' + 'logo'). Themes never fork the receipt templates — they only
 * decide which bold/logo pair gets stored, and receipt_80mm/58mm +
 * fbr-pos/receipt keep reading posReceiptStyle() exactly as before.
 *
 * Pattern mirrors User::WAITER_STYLES: add a theme HERE and it automatically
 * appears in both receipt-settings pickers (PRA + FBR), passes validation,
 * and resolves to the right pre-selected card.
 *
 *   pizza_bold — bold ON + big centered logo (universal default since Jul 2026)
 *   bold_side  — bold ON + small logo beside the name
 *   saada      — bold OFF trimmed drafting look (plain-style opt-out shops)
 */
class PosReceiptThemes
{
    public const THEMES = [
        'pizza_bold' => [
            'bold'  => true,
            'logo'  => 'center',
            'label' => 'pos.rcpt_theme_pizza_bold',
            'hint'  => 'pos.rcpt_theme_pizza_bold_hint',
        ],
        'bold_side' => [
            'bold'  => true,
            'logo'  => 'side',
            'label' => 'pos.rcpt_theme_bold_side',
            'hint'  => 'pos.rcpt_theme_bold_side_hint',
        ],
        'saada' => [
            'bold'  => false,
            'logo'  => 'side',
            'label' => 'pos.rcpt_theme_saada',
            'hint'  => 'pos.rcpt_theme_saada_hint',
        ],
    ];

    /** @return string[] valid theme keys */
    public static function keys(): array
    {
        return array_keys(self::THEMES);
    }

    public static function isValid(?string $theme): bool
    {
        return $theme !== null && isset(self::THEMES[$theme]);
    }

    /**
     * Resolve the theme a saved pos_style belongs to — so old companies see
     * the RIGHT card pre-selected without any data migration.
     * Rule: bold OFF = 'saada' regardless of logo placement (plain opt-out
     * shops may run a center logo; that combo is still the Saada theme).
     */
    public static function resolve(array $style): string
    {
        $bold = filter_var($style['bold'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (!$bold) {
            return 'saada';
        }

        return (($style['logo'] ?? 'center') === 'side') ? 'bold_side' : 'pizza_bold';
    }

    /**
     * The bold/logo pair to STORE when a theme is saved.
     *
     * No-op guard (owner rule: plain opt-out companies' choice must never be
     * overwritten): when the submitted theme equals the theme the current
     * style already resolves to, the stored pair is returned UNCHANGED —
     * re-saving the page never rewrites a company's bold/logo combo (e.g. a
     * Saada shop with a center logo keeps its center logo). Only an ACTIVE
     * theme switch writes the theme's canonical bundle.
     *
     * @return array{bold: bool, logo: string}
     */
    public static function apply(string $theme, array $currentStyle): array
    {
        $current = [
            'bold' => filter_var($currentStyle['bold'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'logo' => (($currentStyle['logo'] ?? 'center') === 'side') ? 'side' : 'center',
        ];

        if (!isset(self::THEMES[$theme]) || self::resolve($currentStyle) === $theme) {
            return $current;
        }

        return [
            'bold' => self::THEMES[$theme]['bold'],
            'logo' => self::THEMES[$theme]['logo'],
        ];
    }

    /**
     * Minimal key => ['bold' =>, 'logo' =>] map for the client-side live
     * preview (Alpine). Labels/hints stay server-rendered (three-language).
     */
    public static function clientMap(): array
    {
        $map = [];
        foreach (self::THEMES as $key => $def) {
            $map[$key] = ['bold' => $def['bold'], 'logo' => $def['logo']];
        }

        return $map;
    }
}
