<?php

namespace App\Support;

/**
 * The healthcare panel's identity — one place, so nothing has to guess.
 *
 * Every other product line spells its own identity out in string literals
 * scattered across controllers; the healthcare line keeps it here instead so a
 * rename can never leave one path pointing at the old value.
 */
final class HealthPanel
{
    /** companies.product_type / pricing_plans.product_type value. */
    public const PRODUCT_TYPE = 'health';

    /** Auth guard name (config/auth.php). */
    public const GUARD = 'health';

    /** URL prefix owned by this panel (no leading slash). */
    public const PATH_PREFIX = 'health';

    /** Public product name. */
    public const LABEL = 'Healthcare ERP';

    /** Login page, path-relative on purpose (forced-https absolutes break dev). */
    public const LOGIN_PATH = '/health/login';

    /** Landing page for signed-out visitors. */
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
}
