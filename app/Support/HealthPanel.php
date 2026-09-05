<?php

namespace App\Support;

/**
 * The HEALTHCARE VERTICAL's identity — one place, so nothing has to guess.
 *
 * Healthcare is not a product line of its own: it is the first vertical of
 * Nest ERPS (see App\Support\NestErps). This class therefore no longer owns the
 * product name or the product discriminator — it reads both from the umbrella
 * and adds only what is specific to healthcare (organisation types, and the
 * vertical's own paths pulled out of the registry).
 *
 * A second vertical does NOT copy this file: it registers one entry in
 * NestErps::VERTICALS and builds its own screens.
 */
final class HealthPanel
{
    /** Which Nest ERPS vertical this panel is. */
    public const VERTICAL = NestErps::HEALTH;

    /** companies.product_type / pricing_plans.product_type value (the UMBRELLA's). */
    public const PRODUCT_TYPE = NestErps::PRODUCT_TYPE;

    /** Auth guard name (config/auth.php). */
    public const GUARD = 'health';

    /** URL prefix owned by this panel (no leading slash). */
    public const PATH_PREFIX = 'health';

    /** Public product name — the line, with this vertical as its sub-label. */
    public const LABEL = NestErps::LABEL . ' — Healthcare';

    /** Login page, path-relative on purpose (forced-https absolutes break dev). */
    public const LOGIN_PATH = '/health/login';

    /**
     * Landing page for signed-out visitors.
     *
     * Still /healthcare: saved links, bookmarks and the logout redirect must
     * keep working. It now serves the Nest ERPS hub, which the canonical
     * NestErps::LANDING_PATH also serves.
     */
    public const LANDING_PATH = '/healthcare';

    /** Organisation types a healthcare company can be. */
    public const ORG_TYPES = ['clinic', 'hospital', 'lab', 'pharmacy'];

    public const DEFAULT_ORG_TYPE = 'clinic';

    public static function isOrgType(?string $type): bool
    {
        return in_array($type, self::ORG_TYPES, true);
    }

    public static function normalizeOrgType(?string $type): string
    {
        return self::isOrgType($type) ? $type : self::DEFAULT_ORG_TYPE;
    }

    /** Lang key for an org type's label. */
    public static function orgTypeLabelKey(?string $type): string
    {
        return 'health.org_type_' . self::normalizeOrgType($type);
    }

    /**
     * May a signed-out visitor create a healthcare organisation themselves?
     *
     * The panel is deployed to production while the product is still
     * pre-pilot, so the answer is normally NO: the code is live and provable,
     * but the front door is shut. One predicate, so the route guard, the
     * controller and every call-to-action can never disagree about it.
     */
    public static function registrationOpen(): bool
    {
        return (bool) config('health.registration_open', false);
    }

    /** True when the request path belongs to this panel. */
    public static function ownsPath(string $path): bool
    {
        $path = ltrim($path, '/');

        return $path === self::PATH_PREFIX || str_starts_with($path, self::PATH_PREFIX . '/');
    }

    /**
     * Does this stored product_type belong to the panel?
     *
     * Tolerant of the value rows held before the umbrella existed — see
     * NestErps::PRODUCT_TYPES.
     */
    public static function isProductType(?string $productType): bool
    {
        return NestErps::isProductType($productType);
    }
}
