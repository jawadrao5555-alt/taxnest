<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Support\HealthPanel;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare access control — the ONE place that answers "may this person do
 * this?" for the Nest ERPS Healthcare panel.
 *
 * Three layers, applied in this order:
 *
 *   1. MODULE       — a capability whose module is switched off does not exist
 *                     for anybody, owner included (HealthModuleService).
 *   2. ROLE         — least-privilege defaults per role. A receptionist has no
 *                     clinical write, an accountant has no patient notes, an
 *                     auditor has nothing but read.
 *   3. DELEGATION   — the owner may store an explicit capability set on a
 *                     member (users.health_permissions). When present it
 *                     REPLACES the role defaults, so it both expands and
 *                     restricts — the same contract POS Custom Access uses, and
 *                     for the same reason: two overlapping notions of "allowed"
 *                     always drift.
 *
 * Medical and financial data get least-privilege defaults on purpose: clinical
 * notes and the accounts ledger are the two things a wrong tick hurts most, so
 * neither is ever granted to a role that does not need it to do its job.
 */
class HealthAccessService
{
    /** The organisation owner. Never restricted, never delegatable away. */
    public const ROLE_OWNER = 'health_owner';

    /**
     * Every healthcare role, in display order. The key is what lands in
     * users.health_role; the label is a lang key.
     */
    public const ROLES = [
        self::ROLE_OWNER,
        'health_admin',
        'health_receptionist',
        'health_doctor',
        'health_nurse',
        'health_pharmacist',
        'health_lab_tech',
        'health_accountant',
        'health_auditor',
        'health_cashier',
        'health_hr',
    ];

    /**
     * Role → default capability set (least privilege).
     *
     * The owner is deliberately absent: owner access is computed as "every
     * capability the enabled modules expose", so a new module never needs this
     * table edited to stay reachable by the person who bought the product.
     */
    public const ROLE_CAPABILITIES = [
        // Runs the organisation day to day, but is not a clinician, does not
        // dispense, does not sign out lab results and does not post the ledger.
        'health_admin' => [
            'dashboard.view',
            'patients.view', 'patients.manage',
            'appointments.view', 'appointments.manage',
            'doctors.manage',
            'clinical.view',
            'pharmacy.view', 'pharmacy.manage',
            'ipd.view', 'ipd.manage', 'ipd.charge', 'ipd.discharge',
            'wards.manage',
            'operations.view', 'operations.manage',
            'lab.view',
            'billing.view', 'billing.charge',
            // Reads the books and signs off what the accountant prepared —
            // period close and doctor payouts — without being able to post the
            // ledger itself.
            'accounts.view', 'accounts.approve',
            'hr.view', 'hr.manage',
            'hr.attendance.view', 'hr.attendance.correct', 'hr.attendance.approve',
            'hr.leave.approve',
            'hr.payroll.view',
            'reports.view',
            'departments.manage',
            'staff.manage',
            'settings.manage',
        ],
        // Front desk: registers patients and books them in. No clinical data.
        'health_receptionist' => [
            'dashboard.view',
            'patients.view', 'patients.manage',
            'appointments.view', 'appointments.manage',
            'billing.view',
        ],
        // Sees and writes the clinical record for the patients in front of them.
        'health_doctor' => [
            'dashboard.view',
            'patients.view',
            'appointments.view', 'appointments.manage',
            'clinical.view', 'clinical.write',
            // A consultant admits, rounds and writes the discharge order. The
            // RELEASE itself (ipd.discharge) stays with accounts — a doctor
            // saying "she can go home" and the hospital letting her walk out
            // past an unpaid bill are two different decisions.
            'ipd.view', 'ipd.manage',
            'operations.view', 'operations.manage',
            'lab.view',
            'pharmacy.view',
        ],
        // Records observations and runs the ward. No prescribing.
        'health_nurse' => [
            'dashboard.view',
            'patients.view',
            'appointments.view',
            'clinical.view', 'nursing.record',
            'ipd.view', 'ipd.manage',
            'operations.view',
            'lab.view',
        ],
        // Reads the prescription, dispenses against it, keeps the counter.
        'health_pharmacist' => [
            'dashboard.view',
            'patients.view',
            'clinical.view',
            'pharmacy.view', 'pharmacy.dispense', 'pharmacy.manage',
        ],
        // Collects samples and signs results out. No other clinical access.
        'health_lab_tech' => [
            'dashboard.view',
            'patients.view',
            'lab.view', 'lab.collect', 'lab.result',
        ],
        // Owns the money side. Reaches an inpatient stay to post its charges,
        // take advances and clear the bill — but holds neither clinical.view
        // nor ipd.manage, so every screen withholds the clinical narrative and
        // no ward move is possible from this account. Reads the payroll handoff
        // too, because somebody has to pay the staff, but cannot alter the
        // attendance it is built from.
        'health_accountant' => [
            'dashboard.view',
            'billing.view', 'billing.charge',
            'ipd.view', 'ipd.charge', 'ipd.discharge',
            'accounts.view', 'accounts.manage',
            'hr.view', 'hr.payroll.view',
            'reports.view',
        ],
        // Read-only by definition (see READ_ONLY_ROLES). Clinical notes are
        // deliberately NOT included: an audit of the books is not a reason to
        // read a patient's diagnosis.
        'health_auditor' => [
            'dashboard.view',
            'patients.view',
            'pharmacy.view',
            'ipd.view',
            'operations.view',
            'lab.view',
            'billing.view',
            'accounts.view',
            'hr.view', 'hr.attendance.view', 'hr.payroll.view',
            'reports.view',
            'audit.view',
        ],
        // Takes the payment at the counter. Nothing else — including on a
        // stay: an advance may be received, but the bill may not be cleared
        // and no concession may be approved.
        'health_cashier' => [
            'dashboard.view',
            'patients.view',
            'billing.view', 'billing.charge',
            'ipd.charge',
        ],
        // Staff records, rosters, leave and attendance — the whole HR desk.
        // Never patients, never the ledger. Holds the payroll handoff because
        // HR is who hands it over, but it stays an attendance export, not a
        // payroll run.
        'health_hr' => [
            'dashboard.view',
            'hr.view', 'hr.manage',
            'hr.attendance.view', 'hr.attendance.correct', 'hr.attendance.approve',
            'hr.leave.approve',
            'hr.payroll.view',
            'reports.view',
        ],
    ];

    /**
     * Roles the owner may attach a custom capability set to.
     *
     * The owner is excluded (no self-lockout) and the auditor is excluded on
     * purpose: "auditor" is a read-only guarantee the organisation relies on,
     * so it must not be quietly turned into a write account by a tick. Change
     * the person's role instead.
     */
    public const CUSTOMIZABLE_ROLES = [
        'health_admin',
        'health_receptionist',
        'health_doctor',
        'health_nurse',
        'health_pharmacist',
        'health_lab_tech',
        'health_accountant',
        'health_cashier',
        'health_hr',
    ];

    /** Roles that may never hold a write capability, however they were granted. */
    public const READ_ONLY_ROLES = ['health_auditor'];

    /**
     * Capability suffixes that count as a write for READ_ONLY_ROLES.
     *
     * `correct` and `approve` joined the list with healthcare HR: approving a
     * colleague's attendance correction or leave is a write in every sense that
     * matters, so an auditor must not hold it however it was granted.
     */
    private const WRITE_SUFFIXES = ['manage', 'write', 'charge', 'dispense', 'collect', 'result', 'record', 'correct', 'approve'];

    /** Capabilities only the organisation owner may ever exercise. */
    public const OWNER_ONLY = ['settings.manage.modules', 'staff.delegate'];

    public static function roleLabelKey(?string $role): string
    {
        return 'health.role_' . self::normalizeRole($role);
    }

    public static function capabilityLabelKey(string $capability): string
    {
        return 'health.cap_' . str_replace('.', '_', $capability);
    }

    public static function isRole(?string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /** Unknown / missing role → the most restrictive real role we have. */
    public static function normalizeRole(?string $role): string
    {
        return self::isRole($role) ? $role : 'health_cashier';
    }

    /**
     * The healthcare role of a user, derived safely.
     *
     * A company_admin (the account created at signup) is ALWAYS the healthcare
     * owner even if the column never got written — otherwise a schema-drift box
     * would lock the person who bought the product out of their own settings.
     */
    public static function roleFor(?User $user): ?string
    {
        if (!$user) {
            return null;
        }

        if (($user->role ?? null) === 'company_admin') {
            return self::ROLE_OWNER;
        }

        $stored = null;
        try {
            if (Schema::hasColumn('users', 'health_role')) {
                $stored = $user->getAttributeValue('health_role');
            }
        } catch (\Throwable $e) {
            $stored = null;
        }

        return self::isRole($stored) ? $stored : null;
    }

    public static function isOwner(?User $user): bool
    {
        return self::roleFor($user) === self::ROLE_OWNER;
    }

    /** Owner or administrator — the two roles that see across every branch. */
    public static function isAdministrative(?User $user): bool
    {
        return in_array(self::roleFor($user), [self::ROLE_OWNER, 'health_admin'], true);
    }

    public static function isReadOnly(?User $user): bool
    {
        return in_array(self::roleFor($user), self::READ_ONLY_ROLES, true);
    }

    private static function isWriteCapability(string $capability): bool
    {
        $parts = explode('.', $capability);
        $suffix = end($parts);

        return in_array($suffix, self::WRITE_SUFFIXES, true);
    }

    /**
     * The owner's explicit set for this member, or NULL when none is stored.
     *
     * Intersected with the product's known capability list so a key removed
     * from the product in a later release cannot linger in a saved set.
     */
    public static function customSet(?User $user): ?array
    {
        if (!$user || !in_array(self::roleFor($user), self::CUSTOMIZABLE_ROLES, true)) {
            return null;
        }

        try {
            if (!Schema::hasColumn('users', 'health_permissions')) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        $raw = $user->getAttributeValue('health_permissions');
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return array_values(array_intersect(HealthModuleService::allCapabilities(), $decoded));
    }

    /**
     * Everything this person can actually do right now, after all three layers.
     *
     * @param  Company|null  $company  the user's company (pass it to avoid a lazy load)
     */
    public static function capabilitiesFor(?User $user, ?Company $company = null): array
    {
        if (!$user) {
            return [];
        }

        $role = self::roleFor($user);
        if ($role === null) {
            return [];
        }

        $available = HealthModuleService::availableCapabilities($company);

        // Owner: everything the enabled modules expose.
        if ($role === self::ROLE_OWNER) {
            return $available;
        }

        $granted = self::customSet($user) ?? (self::ROLE_CAPABILITIES[$role] ?? []);

        // Layer 1 always wins: an OFF module removes the capability entirely.
        $granted = array_values(array_intersect($available, $granted));

        // A read-only role stays read-only no matter what was stored.
        if (in_array($role, self::READ_ONLY_ROLES, true)) {
            $granted = array_values(array_filter(
                $granted,
                fn (string $capability) => !self::isWriteCapability($capability)
            ));
        }

        return $granted;
    }

    /** The single predicate every healthcare guard, nav item and view must use. */
    public static function can(?User $user, string $capability, ?Company $company = null): bool
    {
        if (!$user) {
            return false;
        }

        if (in_array($capability, self::OWNER_ONLY, true)) {
            return self::isOwner($user);
        }

        return in_array($capability, self::capabilitiesFor($user, $company), true);
    }

    /**
     * Capabilities the owner may tick for a member on the team screen — the
     * enabled modules' capabilities minus the ones a role can never hold.
     */
    public static function delegatableCapabilities(?Company $company): array
    {
        return array_values(array_diff(
            HealthModuleService::availableCapabilities($company),
            self::OWNER_ONLY
        ));
    }

    /**
     * Store (or clear) an owner-delegated set.
     *
     * Passing null clears the set and returns the member to role defaults.
     * Anything outside the product's capability list is dropped rather than
     * saved, so a tampered form can never widen access.
     */
    public static function setCustomSet(User $user, ?array $capabilities, ?Company $company = null): ?array
    {
        if (!in_array(self::roleFor($user), self::CUSTOMIZABLE_ROLES, true)) {
            return null;
        }

        try {
            if (!Schema::hasColumn('users', 'health_permissions')) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        if ($capabilities === null) {
            $user->health_permissions = null;
            $user->save();

            return null;
        }

        $clean = array_values(array_intersect(
            self::delegatableCapabilities($company),
            $capabilities
        ));

        $user->health_permissions = json_encode($clean);
        $user->save();

        return $clean;
    }

    /**
     * Path prefix → capability. Used by the panel middleware so a route can
     * never be reachable just because someone forgot the middleware argument.
     * Checked in order; the FIRST match wins, so specific prefixes come first.
     * A path that matches nothing is open to any authenticated panel user
     * (dashboard, profile, language, logout).
     */
    private const PATH_MAP = [
        '#^health/settings/modules#'  => 'settings.manage.modules',
        '#^health/settings#'          => 'settings.manage',
        '#^health/departments#'       => 'departments.manage',
        '#^health/team#'              => 'staff.manage',
        '#^health/patients#'          => 'patients.view',
        '#^health/doctors#'           => 'doctors.manage',
        '#^health/appointments#'      => 'appointments.view',
        // OR: a ward nurse holds nursing.record without general clinical
        // reading. The screen itself decides what each of them may write.
        '#^health/clinical#'          => 'clinical.view|nursing.record',
        '#^health/pharmacy#'          => 'pharmacy.view',
        // OR: the accounts counter reaches a stay to take an advance and clear
        // the bill without holding the ward's own view. The screens themselves
        // withhold the clinical narrative from anyone who only holds the money
        // capabilities.
        '#^health/ipd#'               => 'ipd.view|ipd.charge|ipd.discharge',
        '#^health/operations#'        => 'operations.view',
        '#^health/lab#'               => 'lab.view',
        '#^health/billing#'           => 'billing.view',
        '#^health/accounts#'          => 'accounts.view',
        // First match wins, so the HR sub-desks sit above the generic rule.
        // The payroll handoff is the one an accountant reaches without holding
        // hr.manage, and attendance is the one a duty manager reaches without
        // seeing anybody's salary.
        '#^health/hr/payroll#'        => 'hr.payroll.view',
        '#^health/hr/attendance#'     => 'hr.attendance.view',
        '#^health/hr/corrections#'    => 'hr.attendance.view',
        '#^health/hr#'                => 'hr.view',
        '#^health/audit#'             => 'audit.view',
        '#^health/reports#'           => 'reports.view',
    ];

    /**
     * The capability a request path requires, or null when it needs none.
     *
     * A value may list ALTERNATIVES separated by '|' — holding any one of
     * them opens the path. Use it only where two different jobs legitimately
     * reach the same screen for different reasons.
     */
    public static function capabilityForPath(string $path): ?string
    {
        $path = ltrim($path, '/');

        foreach (self::PATH_MAP as $pattern => $capability) {
            if (preg_match($pattern, $path)) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * Does this person hold ANY of a '|'-separated capability list?
     *
     * The single-capability case still goes through can(), so owner-only
     * capabilities, module gating and custom permission sets behave exactly
     * as they do everywhere else.
     */
    public static function canAny(?User $user, string $capabilities, ?Company $company = null): bool
    {
        foreach (explode('|', $capabilities) as $capability) {
            $capability = trim($capability);
            if ($capability !== '' && self::can($user, $capability, $company)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Roles confined to one screen: signing in lands them there and they may
     * not wander. Empty for now — every foundation role gets the full panel
     * filtered by its capabilities — but the hook exists so a later module can
     * add a kiosk-style role without inventing a second mechanism.
     *
     * @return array<string,string> role => landing path
     */
    public const CONFINED_ROLES = [];

    /** Where a role lands after signing in. */
    public static function homePathFor(?User $user): string
    {
        $role = self::roleFor($user);

        return self::CONFINED_ROLES[$role] ?? '/' . HealthPanel::PATH_PREFIX . '/dashboard';
    }
}
