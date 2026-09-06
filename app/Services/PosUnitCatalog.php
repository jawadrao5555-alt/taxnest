<?php

namespace App\Services;

use App\Models\Company;

/**
 * ONE unit-of-measure catalogue for BOTH POS panels (PRA + FBR POS).
 *
 * Why this exists: the unit list used to be copied into eight places (FBR
 * product form, sale-screen quick-create, per-line select, stock quick-edit,
 * three `in:` validators, PRA add/edit modals, Excel import defaults), each
 * marked "KEEP IN SYNC", and every shop — a pharmacy, a hotel, a gym — saw the
 * same 22 goods codes. Neither regulator payload carries the unit (FBR IMS
 * sends ItemCode/PCTCode/Quantity, PRA sends none), so it is purely OUR field:
 * it drives the decimal-quantity / value-mode rule and what the receipt and
 * product record say. That makes it safe to tailor per business category.
 *
 * Rules every consumer relies on:
 *   - A stored code NEVER becomes invalid or unpickable: the grouped options
 *     always contain the current value, and validCodes() covers every code a
 *     shop could ever have picked (legacy NOS/KGS included).
 *   - Digital Invoice (DI) is NOT a consumer: DI uses FBR's HS-code-driven
 *     descriptive UoM validated against the HS_UOM API.
 *   - Labels come from lang/{en,rur,ur}/pos.php key `uom_<code>` (lower-case),
 *     so every unit exists in all three POS languages.
 */
final class PosUnitCatalog
{
    /**
     * Master list — code => [translation key suffix, measure?].
     *
     * "measure" = a weight / volume / length / area / distance / duration unit
     * that is sold in fractions (0.5 KG, 1.25 LTR, 2.5 FT, 1.5 HR) and may be
     * entered by Rs value on the FBR sale screen. Everything else is a COUNT
     * unit and must stay a whole number.
     */
    public const UNITS = [
        // ── count / packaging (goods) ─────────────────────────────────────
        'U'     => ['u', false],
        'PCS'   => ['pcs', false],
        'PKT'   => ['pkt', false],
        'DOZ'   => ['doz', false],
        'BOX'   => ['box', false],
        'CTN'   => ['ctn', false],
        'BAG'   => ['bag', false],
        'BTL'   => ['btl', false],
        'TIN'   => ['tin', false],
        'CAN'   => ['can', false],
        'BUN'   => ['bun', false],
        'ROL'   => ['rol', false],
        'SET'   => ['set', false],
        'STRIP' => ['strip', false],
        'TUBE'  => ['tube', false],
        'PAIR'  => ['pair', false],
        'SUIT'  => ['suit', false],
        // ── weight / volume / length / area / distance (measure) ──────────
        'KG'    => ['kg', true],
        'GM'    => ['gm', true],
        'LB'    => ['lb', true],
        'LTR'   => ['ltr', true],
        'ML'    => ['ml', true],
        'MTR'   => ['mtr', true],
        'FT'    => ['ft', true],
        'IN'    => ['in', true],
        'YDS'   => ['yds', true],
        'SQM'   => ['sqm', true],
        'SQFT'  => ['sqft', true],
        'KM'    => ['km', true],
        // ── services ──────────────────────────────────────────────────────
        'NOS'   => ['nos', false],
        'JOB'   => ['job', false],
        'SES'   => ['ses', false],
        'HR'    => ['hr', true],
        'DAY'   => ['day', false],
        'NGT'   => ['ngt', false],
        'MON'   => ['mon', false],
        'HEAD'  => ['head', false],
        'TRIP'  => ['trip', false],
        // ── legacy alias (PRA products stored before this catalogue) ──────
        'KGS'   => ['kgs', true],
    ];

    /**
     * The 22 goods codes every FBR shop had before the catalogue, in the
     * order the product form always listed them. A general / retail shop
     * keeps exactly this list, U first.
     */
    public const GOODS_ALL = [
        'U', 'PCS', 'KG', 'GM', 'LTR', 'ML', 'MTR', 'SQM', 'FT', 'IN', 'YDS',
        'PKT', 'DOZ', 'BOX', 'CTN', 'BAG', 'BTL', 'TIN', 'CAN', 'BUN', 'ROL', 'SET',
    ];

    /** Fallback recommendation when a PRA category has no entry of its own. */
    public const PRA_DEFAULT = ['NOS', 'JOB', 'PCS', 'SES', 'HR', 'DAY'];

    /**
     * Per-business-category recommended units — the FIRST entry is the
     * default unit of a brand-new product. Keys are the slugs from
     * PosFeatureService::PANEL_CATEGORIES + LEGACY_CATEGORIES; a category is
     * looked up here regardless of panel, so an FBR restaurant (ICT) and a PRA
     * restaurant read the same row. Categories absent here fall back to
     * GOODS_ALL (FBR panel) or PRA_DEFAULT (PRA panel).
     */
    public const CATEGORY_UNITS = [
        // ── goods (FBR panel + legacy presets) ────────────────────────────
        'general'            => self::GOODS_ALL,
        'retail'             => self::GOODS_ALL,
        'pharmacy'           => ['PCS', 'STRIP', 'BTL', 'TUBE', 'BOX', 'PKT', 'ML', 'GM', 'U'],
        'grocery'            => ['PCS', 'KG', 'GM', 'LTR', 'ML', 'PKT', 'DOZ', 'BAG', 'BTL', 'BOX', 'CTN', 'U'],
        'bakery'             => ['PCS', 'KG', 'LB', 'GM', 'DOZ', 'PKT', 'BOX', 'U'],
        'clothing'           => ['PCS', 'MTR', 'SUIT', 'PAIR', 'YDS', 'SET', 'U'],
        'electronics'        => ['PCS', 'SET', 'BOX', 'MTR', 'PAIR', 'U'],
        'hardware'           => ['PCS', 'KG', 'MTR', 'FT', 'SQFT', 'LTR', 'PKT', 'BAG', 'BUN', 'ROL', 'SET', 'U'],
        'autoparts'          => ['PCS', 'SET', 'PAIR', 'LTR', 'BOX', 'U'],
        'wholesale'          => ['CTN', 'BOX', 'DOZ', 'PKT', 'BAG', 'KG', 'PCS', 'U'],
        'hybrid_cafe_retail' => ['PCS', 'KG', 'LTR', 'PKT', 'BOX', 'U'],
        // ── food service (both panels) ────────────────────────────────────
        'restaurant'         => ['NOS', 'PCS', 'KG', 'LTR', 'HEAD'],
        'cafe'               => ['NOS', 'PCS', 'KG', 'LTR', 'HEAD'],
        'quick_service'      => ['NOS', 'PCS', 'KG', 'LTR', 'HEAD'],
        // ── services (PRA panel; salon also on FBR/ICT) ───────────────────
        'salon'              => ['SES', 'JOB', 'HR', 'PCS', 'NOS'],
        'hotel'              => ['NGT', 'DAY', 'HR', 'HEAD', 'NOS'],
        'marquee'            => ['HEAD', 'DAY', 'JOB', 'NOS'],
        'catering'           => ['HEAD', 'KG', 'PCS', 'JOB', 'NOS'],
        'laundry'            => ['PCS', 'KG', 'PAIR', 'SUIT', 'JOB', 'NOS'],
        'gym'                => ['MON', 'DAY', 'SES', 'HR', 'NOS'],
        'workshop'           => ['JOB', 'HR', 'PCS', 'NOS'],
        'courier'            => ['PCS', 'KG', 'TRIP', 'KM', 'JOB', 'NOS'],
        'photography'        => ['SES', 'HR', 'DAY', 'JOB', 'PCS', 'NOS'],
        'event_management'   => ['JOB', 'DAY', 'HEAD', 'HR', 'NOS'],
        'travel_agent'       => ['TRIP', 'HEAD', 'NGT', 'DAY', 'JOB', 'NOS'],
        'rent_a_car'         => ['KM', 'TRIP', 'DAY', 'HR', 'NOS'],
        'property_dealer'    => ['JOB', 'SQFT', 'MON', 'NOS'],
        'advertising'        => ['JOB', 'DAY', 'MON', 'SQFT', 'NOS'],
        'it_services'        => ['JOB', 'HR', 'MON', 'NOS'],
        'security_services'  => ['MON', 'DAY', 'HR', 'HEAD', 'NOS'],
        'clinic'             => ['SES', 'JOB', 'DAY', 'HR', 'NOS'],
        'education'          => ['SES', 'MON', 'HR', 'DAY', 'NOS'],
        'consultant'         => ['SES', 'HR', 'JOB', 'NOS'],
        'architect'          => ['JOB', 'SQFT', 'HR', 'NOS'],
        'construction'       => ['SQFT', 'JOB', 'DAY', 'NOS'],
        'manpower'           => ['HEAD', 'DAY', 'MON', 'HR', 'NOS'],
        'cargo'              => ['KM', 'TRIP', 'KG', 'DAY', 'NOS'],
        'warehouse'          => ['SQFT', 'MON', 'DAY', 'PCS', 'NOS'],
        'cleaning'           => ['JOB', 'SQFT', 'HR', 'DAY', 'NOS'],
        'repair_service'     => ['JOB', 'HR', 'PCS', 'NOS'],
        'printing'           => ['PCS', 'SQFT', 'JOB', 'SET', 'NOS'],
        'media_production'   => ['JOB', 'HR', 'DAY', 'SES', 'NOS'],
        'entertainment'      => ['HEAD', 'HR', 'SES', 'DAY', 'NOS'],
        'financial_services' => ['JOB', 'MON', 'NOS'],
        'equipment_rental'   => ['DAY', 'HR', 'MON', 'TRIP', 'NOS'],
        'tailoring'          => ['SUIT', 'PCS', 'PAIR', 'JOB', 'NOS'],
        'other_service'      => ['NOS', 'JOB', 'SES', 'HR', 'DAY'],
    ];

    /** Every code a unit field may hold (validation allow-list). */
    public static function validCodes(): array
    {
        return array_keys(self::UNITS);
    }

    /**
     * Codes that permit decimal quantities + Rs value-mode entry — the
     * measure flags of UNITS, spelled out so a class constant
     * (FbrPosController::VALUE_MODE_UOMS) can alias it. A test pins the two
     * spellings to each other.
     */
    public const MEASURE_CODES = [
        'KG', 'GM', 'LB', 'LTR', 'ML', 'MTR', 'FT', 'IN', 'YDS', 'SQM', 'SQFT', 'KM', 'HR', 'KGS',
    ];

    /** Codes that permit decimal quantities + Rs value-mode entry. */
    public static function measureCodes(): array
    {
        return self::MEASURE_CODES;
    }

    /** Derived from the UNITS flags — what MEASURE_CODES must equal. */
    public static function measureCodesFromFlags(): array
    {
        return array_values(array_keys(array_filter(self::UNITS, fn ($u) => $u[1])));
    }

    public static function isMeasure(?string $code): bool
    {
        $code = self::normalize($code);
        return $code !== null && (self::UNITS[$code][1] ?? false);
    }

    public static function isValid(?string $code): bool
    {
        $code = self::normalize($code);
        return $code !== null && isset(self::UNITS[$code]);
    }

    /**
     * Upper-cased, trimmed code — or null for blank input. Does NOT map
     * unknown strings; callers decide between "keep" and "default".
     */
    public static function normalize(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        return $code === '' ? null : $code;
    }

    /**
     * Tolerant resolution for free-text cells (Excel import): exact code, a
     * known alias, or one of the three language labels. Unknown → null.
     */
    public static function resolve(?string $raw): ?string
    {
        $code = self::normalize($raw);
        if ($code === null) {
            return null;
        }
        if (isset(self::UNITS[$code])) {
            return $code;
        }
        $aliases = [
            'UNIT' => 'U', 'UNITS' => 'U', 'NO' => 'NOS', 'NUMBER' => 'NOS', 'NUMBERS' => 'NOS',
            'PC' => 'PCS', 'PIECE' => 'PCS', 'PIECES' => 'PCS', 'PIECS' => 'PCS',
            'KILO' => 'KG', 'KILOGRAM' => 'KG', 'KILOGRAMS' => 'KG', 'KGS.' => 'KGS',
            'G' => 'GM', 'GRAM' => 'GM', 'GRAMS' => 'GM', 'GRM' => 'GM',
            'L' => 'LTR', 'LT' => 'LTR', 'LITER' => 'LTR', 'LITRE' => 'LTR', 'LITERS' => 'LTR', 'LITRES' => 'LTR',
            'M' => 'MTR', 'METER' => 'MTR', 'METRE' => 'MTR', 'METERS' => 'MTR',
            'FEET' => 'FT', 'FOOT' => 'FT', 'INCH' => 'IN', 'INCHES' => 'IN', 'YARD' => 'YDS', 'YARDS' => 'YDS', 'YD' => 'YDS',
            'PACKET' => 'PKT', 'PACKETS' => 'PKT', 'PACK' => 'PKT', 'DOZEN' => 'DOZ', 'BOXES' => 'BOX',
            'CARTON' => 'CTN', 'CARTONS' => 'CTN', 'BAGS' => 'BAG', 'BOTTLE' => 'BTL', 'BOTTLES' => 'BTL',
            'BUNDLE' => 'BUN', 'ROLL' => 'ROL', 'ROLLS' => 'ROL', 'SETS' => 'SET', 'STRIPS' => 'STRIP',
            'TUBES' => 'TUBE', 'PAIRS' => 'PAIR', 'SUITS' => 'SUIT', 'LBS' => 'LB', 'POUND' => 'LB',
            'HOUR' => 'HR', 'HOURS' => 'HR', 'HRS' => 'HR', 'DAYS' => 'DAY', 'NIGHT' => 'NGT', 'NIGHTS' => 'NGT',
            'MONTH' => 'MON', 'MONTHS' => 'MON', 'PERSON' => 'HEAD', 'PERSONS' => 'HEAD', 'HEADS' => 'HEAD', 'PAX' => 'HEAD',
            'TRIPS' => 'TRIP', 'SESSION' => 'SES', 'SESSIONS' => 'SES', 'VISIT' => 'SES', 'JOBS' => 'JOB',
            'SQ.FT' => 'SQFT', 'SQ FT' => 'SQFT', 'SFT' => 'SQFT', 'SQ.M' => 'SQM', 'SQ M' => 'SQM',
        ];
        return $aliases[$code] ?? null;
    }

    /**
     * Human label for a code in the CURRENT locale ("Pieces", "پیسز"). An
     * unknown/legacy code falls back to the code itself so a stored value
     * always renders something.
     */
    public static function label(?string $code): string
    {
        $code = self::normalize($code);
        if ($code === null) {
            return '';
        }
        $suffix = self::UNITS[$code][0] ?? null;
        if ($suffix === null) {
            return $code;
        }
        $key = 'pos.uom_' . $suffix;
        $label = __($key);
        return $label === $key ? $code : (string) $label;
    }

    /** "PCS — Pieces": the option text every dropdown shows. */
    public static function optionText(?string $code): string
    {
        $code = self::normalize($code) ?? '';
        $label = self::label($code);
        return ($label === '' || $label === $code) ? $code : $code . ' — ' . $label;
    }

    /**
     * Recommended codes for a company, from the SAME category resolver every
     * other preset consumer uses (business_category first, pos_type fallback)
     * — so an admin re-filing the shop changes the list on the next load.
     */
    public static function recommendedFor(?Company $company): array
    {
        $panel = PosFeatureService::panelFor($company);
        // Same precedence as PosFeatureService::resolveCategory (stored
        // business_category, then the signup pos_type) — but its final
        // 'restaurant' fallback is a FEATURE preset, not a unit list: an FBR
        // goods shop that never got a category must keep the full goods list
        // with U first, and a category-less PRA shop gets generic services.
        foreach ([$company?->business_category, $company?->pos_type] as $slug) {
            if (is_string($slug) && isset(self::CATEGORY_UNITS[$slug])
                && isset(PosFeatureService::allCategoryDefaults()[$slug])) {
                return self::CATEGORY_UNITS[$slug];
            }
        }
        return $panel === 'fbr' ? self::GOODS_ALL : self::PRA_DEFAULT;
    }

    /** Default unit of a brand-new product for this company. */
    public static function defaultFor(?Company $company): string
    {
        return self::recommendedFor($company)[0];
    }

    /**
     * Grouped options for a dropdown:
     *   [
     *     'recommended' => [['code' => 'PCS', 'label' => 'Pieces', 'text' => 'PCS — Pieces', 'measure' => false], ...],
     *     'rest'        => [...every other master code...],
     *     'current'     => 'PCS'|null,
     *     'default'     => 'PCS',
     *   ]
     * $current (the stored value on edit) is ALWAYS present: if it is a code
     * outside the recommended group it stays in 'rest'; if it is a code the
     * catalogue has never heard of, it is prepended to 'rest' verbatim so the
     * saved unit still renders selected and re-saves unchanged.
     */
    public static function groupsFor(?Company $company, ?string $current = null): array
    {
        $current = self::normalize($current);
        $recommended = self::recommendedFor($company);
        $recSet = array_flip($recommended);

        $rest = [];
        foreach (array_keys(self::UNITS) as $code) {
            if (!isset($recSet[$code])) {
                $rest[] = $code;
            }
        }
        if ($current !== null && !isset(self::UNITS[$current])) {
            array_unshift($rest, $current);
        }

        return [
            'recommended' => array_map([self::class, 'option'], $recommended),
            'rest'        => array_map([self::class, 'option'], $rest),
            'current'     => $current,
            'default'     => $recommended[0],
        ];
    }

    /** Flat code list in dropdown order (recommended first, then the rest). */
    public static function orderedCodesFor(?Company $company, ?string $current = null): array
    {
        $g = self::groupsFor($company, $current);
        return array_merge(array_column($g['recommended'], 'code'), array_column($g['rest'], 'code'));
    }

    private static function option(string $code): array
    {
        return [
            'code'    => $code,
            'label'   => self::label($code),
            'text'    => self::optionText($code),
            'measure' => self::isMeasure($code),
        ];
    }

    /**
     * Validation rule: the value must be a catalogue code (or one of $extra —
     * pass the product's stored unit on edit paths so a legacy/unknown code
     * keeps re-saving). Case-insensitive on purpose: every write path
     * upper-cases before storing, and offline/agent replays have sent
     * lower-case codes before. Use inside an array rule set:
     *     'uom' => ['nullable', 'string', PosUnitCatalog::rule()]
     */
    public static function rule(array $extra = []): \Closure
    {
        $allowed = array_flip(array_merge(self::validCodes(), array_values(array_filter(array_map(
            fn ($c) => self::normalize($c), $extra
        )))));
        return function (string $attribute, $value, \Closure $fail) use ($allowed) {
            $code = is_scalar($value) ? self::normalize((string) $value) : '';
            if ($code === null || $code === '') {
                return; // 'nullable' decides blanks
            }
            if (!isset($allowed[$code])) {
                $fail(__('validation.in', ['attribute' => str_replace('_', ' ', $attribute)]));
            }
        };
    }

    /** Laravel `in:` rule string over the whole catalogue (+ optional extras). */
    public static function inRule(array $extra = []): string
    {
        $codes = array_values(array_unique(array_merge(self::validCodes(), array_values(array_filter(array_map(
            fn ($c) => self::normalize($c), $extra
        ))))));
        return 'in:' . implode(',', $codes);
    }
}
