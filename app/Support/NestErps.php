<?php

namespace App\Support;

use App\Models\Company;

/**
 * Nest ERPS — the fourth product line, and the registry of the verticals that
 * live inside it.
 *
 * Nest ERPS is deliberately ONE product, not a family of products. Healthcare
 * is its first vertical; a school ERP, a factory ERP or whatever is asked for
 * next joins the SAME product line rather than becoming a fifth and sixth
 * product with their own billing, gating and admin plumbing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ADDING A NEW VERTICAL — what a future vertical must register
 * ─────────────────────────────────────────────────────────────────────────────
 *  1. ONE entry in self::VERTICALS below, carrying:
 *       key, label, label_key   — its name, and the lang key that translates it
 *       path_prefix, guard      — its own URL prefix and auth guard
 *       layout                  — the Blade layout its panel shell renders in
 *       login/register/dashboard paths
 *       lang_file               — the lang/{en,rur,ur}/<file>.php trio it owns
 *       module_service          — the class holding its module preset
 *       billable                — [class, method] counting its billable documents
 *  2. That vertical's own screens, routes, guard (config/auth.php) and the
 *     three-language lang trio.
 *
 *  Nothing else. In particular there is NO new product_type, NO new billing
 *  branch and NO new admin allow-list edit: every shared money and gating path
 *  already switches on self::PRODUCT_TYPE and reads the per-vertical detail out
 *  of this registry.
 *
 *  Nothing outside this class may spell the product name or the product
 *  discriminator as a literal.
 */
final class NestErps
{
    /** Public product name. The vertical is always shown UNDER it, never instead of it. */
    public const LABEL = 'Nest ERPS';

    /** One-line description of the line itself (not of any vertical). */
    public const TAGLINE = 'Purpose-built ERPs, built on demand';

    /** companies.product_type / pricing_plans.product_type value. */
    public const PRODUCT_TYPE = 'erps';

    /**
     * Every value that MEANS Nest ERPS in storage.
     *
     * 'health' is what rows held while the line was still presented as a
     * one-off healthcare product. The migration rewrites them, but reads stay
     * tolerant so a deploy-before-migrate window (or a restored older dump)
     * can never make a live organisation look like a Digital Invoice company.
     */
    public const PRODUCT_TYPES = [self::PRODUCT_TYPE, 'health'];

    /** Column carrying the vertical on companies AND pricing_plans. */
    public const VERTICAL_COLUMN = 'erps_vertical';

    /** Public hub page for the line. */
    public const LANDING_PATH = '/nest-erps';

    /** Vertical keys — referenced by name so nothing has to spell the string. */
    public const HEALTH = 'health';

    public const DEFAULT_VERTICAL = self::HEALTH;

    /** @var array<string, array<string, mixed>> */
    public const VERTICALS = [
        self::HEALTH => [
            'key'             => self::HEALTH,
            'label'           => 'Healthcare',
            'label_key'       => 'health.vertical_name',
            'tagline_key'     => 'health.panel_tagline',
            'blurb'           => 'Clinics, hospitals, laboratories and pharmacies — outpatients, pharmacy, inpatients, laboratory, accounts and HR on one panel.',
            'path_prefix'     => 'health',
            'guard'           => 'health',
            'layout'          => 'layouts.health-app',
            'login_path'      => '/health/login',
            'register_path'   => '/health/register',
            'dashboard_path'  => '/health/dashboard',
            'lang_file'       => 'health',
            'module_service'  => \App\Services\HealthModuleService::class,
            'billable'        => [\App\Services\HealthPlatformService::class, 'billableCount'],
            'live'            => true,
        ],
    ];

    /* ───────────────────────── Product discriminator ───────────────────────── */

    /** Is this stored product_type Nest ERPS — old spelling or new? */
    public static function isProductType(?string $productType): bool
    {
        return $productType !== null && in_array($productType, self::PRODUCT_TYPES, true);
    }

    /**
     * The stored values a product_type query must match.
     *
     * Use this instead of `where('product_type', $type)` on any query that can
     * receive Nest ERPS, so a row still holding the old spelling is found.
     *
     * @return string[]
     */
    public static function storedTypesFor(?string $productType): array
    {
        if (self::isProductType($productType)) {
            return self::PRODUCT_TYPES;
        }

        return [(string) $productType];
    }

    /** Canonical spelling for a value that may still be the old one. */
    public static function canonicalProductType(?string $productType): ?string
    {
        return self::isProductType($productType) ? self::PRODUCT_TYPE : $productType;
    }

    /* ───────────────────────────── Verticals ───────────────────────────── */

    /** @return array<string, array<string, mixed>> */
    public static function verticals(): array
    {
        return self::VERTICALS;
    }

    /** @return string[] */
    public static function verticalKeys(): array
    {
        return array_keys(self::VERTICALS);
    }

    public static function hasVertical(?string $vertical): bool
    {
        return $vertical !== null && isset(self::VERTICALS[$vertical]);
    }

    public static function normalizeVertical(?string $vertical): string
    {
        return self::hasVertical($vertical) ? $vertical : self::DEFAULT_VERTICAL;
    }

    /** @return array<string, mixed> */
    public static function vertical(?string $vertical): array
    {
        return self::VERTICALS[self::normalizeVertical($vertical)];
    }

    /**
     * A vertical entry's field, or null when this vertical does not carry it.
     */
    public static function verticalValue(?string $vertical, string $field, $default = null)
    {
        return self::vertical($vertical)[$field] ?? $default;
    }

    /**
     * The vertical a company/plan belongs to.
     *
     * Reads the attribute directly: an Eloquent model whose column has not been
     * migrated yet simply returns null, which normalises to the default — no
     * Schema round trip on a hot path.
     */
    public static function verticalOf($model): string
    {
        $stored = is_object($model) ? ($model->getAttribute(self::VERTICAL_COLUMN) ?? null) : null;

        return self::normalizeVertical(is_string($stored) ? $stored : null);
    }

    /* ─────────────────────────────── Labels ─────────────────────────────── */

    /** Untranslated vertical name ("Healthcare"). */
    public static function verticalLabel(?string $vertical): string
    {
        return (string) self::vertical($vertical)['label'];
    }

    /** Translated vertical name, for panel surfaces. */
    public static function verticalLabelTranslated(?string $vertical): string
    {
        $key = self::verticalValue($vertical, 'label_key');
        if (!$key) {
            return self::verticalLabel($vertical);
        }

        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : self::verticalLabel($vertical);
    }

    /**
     * The product name, with the vertical as a SUB-label.
     * label()            → "Nest ERPS"
     * label('health')    → "Nest ERPS — Healthcare"
     */
    public static function label(?string $vertical = null): string
    {
        if ($vertical === null) {
            return self::LABEL;
        }

        return self::LABEL . ' — ' . self::verticalLabel($vertical);
    }

    /* ───────────────────────── Panel wiring helpers ───────────────────────── */

    /** Auth guards owned by the line — one per vertical. @return string[] */
    public static function guards(): array
    {
        return array_values(array_unique(array_column(self::VERTICALS, 'guard')));
    }

    /** URL prefixes owned by the line (no leading slash). @return string[] */
    public static function pathPrefixes(): array
    {
        return array_values(array_unique(array_column(self::VERTICALS, 'path_prefix')));
    }

    /**
     * Login/logout paths of every vertical — the impersonation allow-list reads
     * this, so a new vertical is covered the day it registers.
     *
     * @return string[] paths without a leading slash
     */
    public static function identityPaths(): array
    {
        $paths = [];
        foreach (self::VERTICALS as $vertical) {
            $prefix = trim((string) ($vertical['path_prefix'] ?? ''), '/');
            if ($prefix === '') {
                continue;
            }
            $paths[] = $prefix . '/login';
            $paths[] = $prefix . '/logout';
        }

        return array_values(array_unique($paths));
    }

    /** The vertical owning a request path, or null. */
    public static function verticalForPath(string $path): ?string
    {
        $path = ltrim($path, '/');
        foreach (self::VERTICALS as $key => $vertical) {
            $prefix = trim((string) ($vertical['path_prefix'] ?? ''), '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return $key;
            }
        }

        return null;
    }

    public static function ownsPath(string $path): bool
    {
        return self::verticalForPath($path) !== null;
    }

    /** The vertical served by an auth guard, or null. */
    public static function verticalForGuard(?string $guard): ?string
    {
        foreach (self::VERTICALS as $key => $vertical) {
            if (($vertical['guard'] ?? null) === $guard) {
                return $key;
            }
        }

        return null;
    }

    public static function guardFor(?string $vertical): string
    {
        return (string) self::vertical($vertical)['guard'];
    }

    public static function loginPath(?string $vertical = null): string
    {
        return (string) self::vertical($vertical)['login_path'];
    }

    public static function registerPath(?string $vertical = null): string
    {
        return (string) self::vertical($vertical)['register_path'];
    }

    public static function dashboardPath(?string $vertical = null): string
    {
        return (string) self::vertical($vertical)['dashboard_path'];
    }

    /* ─────────────────────── Vertical-owned behaviour ─────────────────────── */

    /**
     * The vertical's billable-document count — what a usage-capped grant and
     * the billing screens measure.
     *
     * Explicit on purpose: without it, a Nest ERPS company would be counted by
     * the Digital Invoice fallback, i.e. against a table it never writes to.
     */
    public static function billableCount(?Company $company, $since = null): int
    {
        if (!$company) {
            return 0;
        }

        $callable = self::verticalValue(self::verticalOf($company), 'billable');
        if (!is_array($callable) || !is_callable($callable)) {
            return 0;
        }

        try {
            return (int) call_user_func($callable, (int) $company->id, $since);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
