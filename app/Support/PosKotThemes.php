<?php

namespace App\Support;

/**
 * KOT Themes (Task 716) — SINGLE SOURCE OF TRUTH.
 *
 * Named presets over the EXISTING KOT layout columns (kot_compact +
 * kot_align_center). Presets never fork the kitchen-ticket template — they
 * only decide which compact/align pair gets stored; kitchen-ticket.blade.php
 * keeps reading the same company columns as before.
 *
 * Pattern mirrors PosReceiptThemes (Task 712): add a preset HERE and it
 * automatically appears on the receipt-settings KOT card, passes validation,
 * and resolves to the right pre-selected card.
 *
 *   khula   — left edge, open spacing (deliberate opt-out)
 *   center  — ticket centered on the paper, open spacing (Pizza Master look —
 *             UNIVERSAL DEFAULT since Task 718, owner-approved Aug 2026)
 *   compact — left edge, tight paper-saving layout
 *
 * DEFAULT semantics (Task 718): companies.kot_align_center is NULLABLE.
 * NULL = "shop never made an explicit choice" → resolves to 'center' so an
 * untouched company's KOT prints Pizza Master style (center-bold). An
 * EXPLICIT false (khula/compact card, kitchen-settings save, or a pre-718
 * deliberate compact/margin setup kept by the migration) stays left — the
 * opt-out path the preset cards provide. FBR receipts/day-close read the
 * same column with `?? false` ON PURPOSE (for fbrpos it is the RECEIPT
 * print position, not a KOT look) — never "harmonize" them to this default.
 */
class PosKotThemes
{
    /** Preset an untouched (NULL kot_align_center) company resolves to. */
    public const DEFAULT = 'center';

    public const THEMES = [
        'khula' => [
            'compact' => false,
            'align'   => false,
            'label'   => 'pos.kot_theme_khula',
            'hint'    => 'pos.kot_theme_khula_hint',
        ],
        'center' => [
            'compact' => false,
            'align'   => true,
            'label'   => 'pos.kot_theme_center',
            'hint'    => 'pos.kot_theme_center_hint',
        ],
        'compact' => [
            'compact' => true,
            'align'   => false,
            'label'   => 'pos.kot_theme_compact',
            'hint'    => 'pos.kot_theme_compact_hint',
        ],
    ];

    /** @return string[] valid preset keys */
    public static function keys(): array
    {
        return array_keys(self::THEMES);
    }

    public static function isValid(?string $theme): bool
    {
        return $theme !== null && isset(self::THEMES[$theme]);
    }

    /**
     * Resolve the preset a saved compact/align pair belongs to — so old
     * companies see the RIGHT card pre-selected without any data migration.
     * Rule: compact ON = 'compact' regardless of alignment (a shop that
     * centered its compact ticket is still the Compact preset — same
     * "dominant flag wins" rule as PosReceiptThemes' saada).
     *
     * Task 718: callers must pass the RAW nullable column value for 'align'
     * (never `?? false` it away) — NULL/missing = no explicit choice = the
     * Pizza Master center default. Explicit false = deliberate left (khula).
     */
    public static function resolve(array $pair): string
    {
        $compact = filter_var($pair['compact'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($compact) {
            return 'compact';
        }

        return self::alignBool($pair['align'] ?? null) ? 'center' : 'khula';
    }

    /**
     * NULL-aware align reader: NULL (unset / schema drift) = center default
     * (Pizza Master, Task 718); everything else keeps boolean semantics.
     */
    public static function alignBool(mixed $align): bool
    {
        return $align === null ? true : filter_var($align, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The compact/align pair to STORE when a preset is saved.
     *
     * No-op guard (same owner rule as PosReceiptThemes::apply()): when the
     * submitted preset equals the preset the current pair already resolves
     * to, the stored pair is returned UNCHANGED — re-saving the page never
     * rewrites a company's combo (e.g. a compact shop with a centered ticket
     * keeps its center alignment). Only an ACTIVE switch writes the preset's
     * canonical bundle.
     *
     * @return array{compact: bool, align: bool}
     */
    public static function apply(string $theme, array $currentPair): array
    {
        $current = [
            'compact' => filter_var($currentPair['compact'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // NULL align = the center default (Task 718): a no-op re-save of the
            // pre-selected Center card writes explicit true — same render, and
            // receipts stay frozen (their own receipt_align_center column).
            'align'   => self::alignBool($currentPair['align'] ?? null),
        ];

        if (!isset(self::THEMES[$theme]) || self::resolve($currentPair) === $theme) {
            return $current;
        }

        return [
            'compact' => self::THEMES[$theme]['compact'],
            'align'   => self::THEMES[$theme]['align'],
        ];
    }
}
