<?php

namespace App\Support;

/**
 * The product line-up — ONE place that knows what a product_type is called,
 * which colour it wears in the admin panel, and where its people log in.
 *
 * Before this class each admin screen carried its own little map of labels and
 * badge colours, and each of them had to be remembered when a product line was
 * added. A line that is missing from one of those maps does not fail loudly: it
 * simply renders grey and unnamed, or worse, lands on another product's
 * defaults. Every label/colour/CTA now comes from here.
 *
 * Verticals are NOT products. Nest ERPS carries many verticals behind one
 * product_type — ask NestErps for the vertical, ask this class for the product.
 */
final class ProductCatalog
{
    public const DI     = 'di';
    public const POS    = 'pos';
    public const FBRPOS = 'fbrpos';
    public const ERPS   = NestErps::PRODUCT_TYPE;

    /** Canonical product types, in line-up order. */
    public const TYPES = [self::DI, self::POS, self::FBRPOS, self::ERPS];

    /**
     * Stored values that are accepted on input but rewritten to a canonical
     * type — the old healthcare spelling of Nest ERPS.
     */
    private const LEGACY = ['health' => self::ERPS];

    private const META = [
        self::DI => [
            'label'       => 'Digital Invoice',
            'short'       => 'DI',
            'email_label' => 'TaxNest Digital Invoice',
            'panel'       => 'Digital Invoicing',
            'login'       => '/login',
            'colour'      => 'emerald',
            'badge'       => 'bg-emerald-900/50 text-emerald-300',
            'chip'        => 'bg-emerald-900/30 text-emerald-400',
        ],
        self::POS => [
            'label'       => 'PRA POS',
            'short'       => 'POS',
            'email_label' => 'NestPOS',
            'panel'       => 'NestPOS — PRA Point of Sale',
            'login'       => '/pos/login',
            'colour'      => 'purple',
            'badge'       => 'bg-purple-900/50 text-purple-300',
            'chip'        => 'bg-purple-900/30 text-purple-400',
        ],
        self::FBRPOS => [
            'label'       => 'FBR POS',
            'short'       => 'FPOS',
            'email_label' => 'FBR POS',
            'panel'       => 'Nest FBR POS',
            'login'       => '/fbr-pos/login',
            'colour'      => 'blue',
            'badge'       => 'bg-blue-900/50 text-blue-300',
            'chip'        => 'bg-blue-900/30 text-blue-400',
        ],
        self::ERPS => [
            'label'       => NestErps::LABEL,
            'short'       => 'ERPS',
            'email_label' => NestErps::LABEL,
            'panel'       => NestErps::LABEL,
            'login'       => null,   // per-vertical — resolved through NestErps
            'colour'      => 'teal',
            'badge'       => 'bg-teal-900/50 text-teal-300',
            'chip'        => 'bg-teal-900/30 text-teal-400',
        ],
    ];

    /** Canonical type, or null when the value belongs to no product line. */
    public static function normalize(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        $type = self::LEGACY[$type] ?? $type;

        return isset(self::META[$type]) ? $type : null;
    }

    public static function isKnown(?string $type): bool
    {
        return self::normalize($type) !== null;
    }

    /**
     * Validation rule for a submitted product_type. Accepts the legacy
     * healthcare spelling so a stale open admin form cannot 422 — the write
     * paths normalise it before storing.
     */
    public static function validationRule(): string
    {
        return 'in:' . implode(',', array_merge(self::TYPES, array_keys(self::LEGACY)));
    }

    /**
     * Product name. For Nest ERPS a vertical may be supplied, and it is shown
     * as a sub-label ("Nest ERPS — Healthcare"), never instead of the product.
     */
    public static function label(?string $type, ?string $vertical = null): string
    {
        $type = self::normalize($type);
        if ($type === null) {
            return 'package';
        }
        if ($type === self::ERPS) {
            return NestErps::label($vertical);
        }

        return self::META[$type]['label'];
    }

    /** Upper-case short badge text ("DI", "POS", "FPOS", "ERPS"). */
    public static function shortLabel(?string $type): string
    {
        $type = self::normalize($type);

        return $type === null ? '—' : self::META[$type]['short'];
    }

    /** Label used inside emails and notifications ("your … account"). */
    public static function emailLabel(?string $type, ?string $vertical = null): string
    {
        $type = self::normalize($type);
        if ($type === null) {
            return 'TaxNest';
        }
        if ($type === self::ERPS) {
            return NestErps::label($vertical);
        }

        return self::META[$type]['email_label'];
    }

    /** Human name of the panel the account signs into. */
    public static function panelName(?string $type, ?string $vertical = null): string
    {
        $type = self::normalize($type);
        if ($type === null) {
            return self::META[self::DI]['panel'];
        }
        if ($type === self::ERPS) {
            return NestErps::label($vertical);
        }

        return self::META[$type]['panel'];
    }

    /** Path of the panel's login page (guards are isolated — never cross-link). */
    public static function loginPath(?string $type, ?string $vertical = null): string
    {
        $type = self::normalize($type);
        if ($type === self::ERPS) {
            return NestErps::loginPath($vertical);
        }

        return self::META[$type ?? self::DI]['login'] ?? self::META[self::DI]['login'];
    }

    /** Tailwind colour NAME (used as `text-{colour}-400` etc. in admin views). */
    public static function colour(?string $type): string
    {
        $type = self::normalize($type);

        return $type === null ? 'gray' : self::META[$type]['colour'];
    }

    /** Solid badge classes for a plan card. */
    public static function badgeClass(?string $type): string
    {
        $type = self::normalize($type);

        return $type === null ? 'bg-gray-900/50 text-gray-300' : self::META[$type]['badge'];
    }

    /** Softer chip classes for a company row. */
    public static function chipClass(?string $type): string
    {
        $type = self::normalize($type);

        return $type === null ? 'bg-gray-900/30 text-gray-400' : self::META[$type]['chip'];
    }

    /**
     * [email label, panel name, login URL] — the tuple approval mails,
     * activation notices and trial reminders all need together.
     *
     * @return array{0:string,1:string,2:string}
     */
    public static function cta(?string $type, ?string $vertical = null): array
    {
        return [
            self::emailLabel($type, $vertical),
            self::panelName($type, $vertical),
            url(self::loginPath($type, $vertical)),
        ];
    }

    /** Every product's label, keyed by canonical type — for admin pickers. */
    public static function options(): array
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $out[$type] = self::label($type);
        }

        return $out;
    }
}
