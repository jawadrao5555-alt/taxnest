<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * POS Team Custom Access (Task #111, owner-approved 2 Aug 2026).
 *
 * Optional per-member feature grants that OVERLAY the role defaults:
 * - NULL custom set  → role behavior bilkul unchanged (backward compatible).
 * - Custom set exists → ONLY ticked features are reachable; the rest are
 *   hidden from nav AND blocked at route level (PosAuth) — one source of truth.
 *
 * Scope rules (implementer decisions, documented per the task plan):
 * - Custom access applies ONLY to pos_cashier and pos_manager. Confined roles
 *   (pos_kitchen / pos_waiter / pos_rider / pos_delivery / archive_viewer /
 *   local_viewer) keep their PosAuth path-prefix confinement — it SUPERSEDES
 *   any stored grants (a waiter can never be custom-granted admin pages;
 *   change the role instead). The owner (company_admin / pos_admin) can never
 *   be restricted (no self-lockout).
 * - Grants EXPAND cashier access through PosAdminOnly (e.g. cashier + Customize
 *   tick passes that middleware), and RESTRICT whatever is unticked.
 * - Paths that map to NO feature key (sale screen, invoice APIs, theme/language
 *   prefs, logout...) are always reachable — billing must never break.
 */
class PosAccessService
{
    /** Feature keys shown as tick-boxes on /pos/team (order = display order). */
    public const FEATURES = [
        'dashboard',
        'orders',
        'products',
        'customers',
        'tables',
        'kitchen',
        'deliveries',
        'riders',
        'reports',
        'tax_reports',
        'day_close',
        'inventory',
        'customize',
        'team',
    ];

    /** Roles a custom set may be attached to (all others: ignored). */
    public const CUSTOMIZABLE_ROLES = ['pos_cashier', 'pos_manager'];

    /**
     * URL prefix → feature key map (checked in order; first match wins).
     * Paths are compared without a leading slash. More-specific prefixes
     * MUST come before shorter ones (riders/{id}/settle before riders).
     */
    private const PATH_MAP = [
        // Rider cash settlement belongs to the Deliveries board (cashiers
        // receive rider cash) — NOT to the admin Riders CRUD feature.
        '#^pos/riders/\d+/settle$#' => 'deliveries',
        '#^pos/dashboard#' => 'dashboard',
        '#^pos/restaurant/dashboard#' => 'dashboard',
        '#^pos/transactions#' => 'orders',
        // Order detail / edit / receipt / retry live under the SINGULAR prefix.
        '#^pos/transaction/#' => 'orders',
        '#^pos/archive#' => 'orders',
        '#^pos/local-bills#' => 'orders',
        '#^pos/products#' => 'products',
        '#^pos/customers#' => 'customers',
        '#^pos/restaurant/tables#' => 'tables',
        '#^pos/restaurant/table-management#' => 'tables',
        '#^pos/restaurant/floors#' => 'tables',
        '#^pos/restaurant/kds#' => 'kitchen',
        '#^pos/deliveries#' => 'deliveries',
        '#^pos/riders#' => 'riders',
        '#^pos/reports#' => 'reports',
        '#^pos/tax-reports#' => 'tax_reports',
        '#^pos/day-close#' => 'day_close',
        '#^pos/inventory#' => 'inventory',
        '#^pos/customize#' => 'customize',
        '#^pos/features#' => 'customize',
        // Customize-page POST endpoints. `settings/theme` stays OPEN — it is a
        // per-device display preference every role may change (same for
        // set-language / my-profile / whats-new, which are simply unmapped).
        '#^pos/settings/(?!theme$)#' => 'customize',
        '#^pos/restaurant/kitchen-settings#' => 'customize',
        '#^pos/restaurant/stations#' => 'customize',
        '#^pos/api/enable-pra-integration#' => 'customize',
        '#^pos/services#' => 'customize',
        '#^pos/deals#' => 'customize',
        '#^pos/terminals#' => 'customize',
        '#^pos/pra-settings#' => 'customize',
        '#^pos/billing#' => 'customize',
        '#^pos/business-profile#' => 'customize',
        '#^pos/receipt-settings#' => 'customize',
        '#^pos/printer-settings#' => 'customize',
        '#^pos/public-profile#' => 'customize',
        '#^pos/agent#' => 'customize',
        '#^pos/restaurant/ingredients#' => 'inventory',
        '#^pos/restaurant/recipes#' => 'inventory',
        '#^pos/restaurant/cancelled-orders#' => 'reports',
        '#^pos/team#' => 'team',
    ];

    /**
     * The member's custom feature set, or NULL when custom access does not
     * apply (no set saved, column missing on PROD, non-customizable role).
     */
    public static function customSet(?User $user): ?array
    {
        if (!$user || !in_array($user->pos_role ?? null, self::CUSTOMIZABLE_ROLES, true)) {
            return null;
        }
        // Owner safety: a company_admin can never be restricted.
        if (($user->role ?? null) === 'company_admin') {
            return null;
        }
        $raw = $user->pos_custom_access ?? null; // missing column → null attribute
        if ($raw === null || $raw === '') {
            return null;
        }
        // Plan gate (Aug 2026): Custom Access is Unlimited-only. When the
        // plan lacks it, stored sets go inert — member behaves as a plain
        // standard role (nobody gets locked OUT by a downgrade).
        // (Strict lazy-loading on live: never touch $user->company lazily.)
        // Checked only when a set exists AND a company can be resolved —
        // orphan users (no company_id) keep pre-gate behavior.
        if ($user->relationLoaded('company') || $user->company_id) {
            $company = $user->relationLoaded('company')
                ? $user->company
                : \App\Models\Company::find($user->company_id);
            if (!PosFeatureService::planAllows($company, 'custom_access_enabled')) {
                return null;
            }
        }
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null; // corrupt payload → behave as "no custom set"
        }

        return array_values(array_intersect(self::FEATURES, $decoded));
    }

    /**
     * NULL = no custom set (caller falls back to the role default);
     * true/false = the custom set's explicit verdict for this feature.
     */
    public static function customAllows(?User $user, string $feature): ?bool
    {
        $set = self::customSet($user);
        if ($set === null) {
            return null;
        }
        $allowed = in_array($feature, $set, true);
        // Owner rule (3 Aug 2026): jab company mein koi alag Delivery Manager
        // account (pos_role='pos_delivery') maujood hi nahi, to cashier/manager
        // Deliveries board (rider assign/settle) chala sakte hain — chahe unka
        // custom set 'deliveries' unticked ho. Delivery account bante hi yeh
        // fallback khud band ho jata hai (asal gating wapas lag jati hai).
        // Nav ($posNavCan) + route gate (PosAuth) + posCashierBlocked() sab isi
        // ek verdict se chalte hain — single source of truth barqarar.
        if (!$allowed && $feature === 'deliveries' && self::deliveriesFallbackOpen($user)) {
            return true;
        }

        return $allowed;
    }

    /**
     * TRUE when the company has NO active Delivery Manager account
     * (pos_role='pos_delivery') — the Deliveries board then opens up to
     * cashiers/managers whose custom set left 'deliveries' unticked.
     */
    public static function deliveriesFallbackOpen(?User $user): bool
    {
        if (!$user || !$user->company_id) {
            return false;
        }
        static $cache = [];
        $cid = (int) $user->company_id;
        if (!array_key_exists($cid, $cache)) {
            try {
                $q = User::where('company_id', $cid)->where('pos_role', 'pos_delivery');
                if (Schema::hasColumn('users', 'is_active')) {
                    $q->where('is_active', true);
                }
                $cache[$cid] = !$q->exists();
            } catch (\Throwable $e) {
                $cache[$cid] = false; // fail closed — existing gating stands
            }
        }

        return $cache[$cid];
    }

    /** Feature key a request path belongs to, or NULL (always-allowed path). */
    public static function featureForPath(string $path): ?string
    {
        $path = ltrim($path, '/');
        foreach (self::PATH_MAP as $pattern => $feature) {
            if (preg_match($pattern, $path)) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * Day Close verdict — SINGLE source of truth for nav, dashboard links AND
     * the controller route guards (owner rule, 5 Aug 2026):
     * - Custom Access set (Unlimited/trial) → its explicit tick wins, both ways.
     * - No set + admin/manager → allowed (unchanged).
     * - No set + cashier → company switch `pos_cashier_dayclose` (default OFF —
     *   Day Close is admin/manager work; ANY plan can re-open it in Customize).
     */
    public static function dayCloseAllowed(?User $user, $company = null): bool
    {
        if (!$user) {
            return false;
        }
        $custom = self::customAllows($user, 'day_close');
        if ($custom !== null) {
            return $custom;
        }
        if (!$user->isPosCashier()) {
            return true;
        }
        try {
            $company = $company ?: \App\Models\Company::find($user->company_id);

            // hasColumn guard: PROD drift (migration not yet run) → column
            // missing → attribute null → default OFF for cashiers.
            return (bool) ($company->pos_cashier_dayclose ?? false);
        } catch (\Throwable $e) {
            return false; // fail closed — admin/manager path unaffected
        }
    }

    /** Whether the users.pos_custom_access column exists (PROD drift guard). */
    public static function columnReady(): bool
    {
        try {
            return Schema::hasColumn('users', 'pos_custom_access');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
