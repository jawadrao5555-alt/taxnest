<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Waiter personal-style catalogue — SINGLE SOURCE OF TRUTH (owner, 8 Aug 2026).
     * key = users.pos_personal_style value, value = lang key for the label.
     * Add a new waiter theme HERE and it automatically appears in the waiter
     * tablet's theme picker, passes saveStyle() validation, and resolves in
     * both layout resolvers (pos-app.blade.php + waiter.blade.php).
     */
    public const WAITER_STYLES = [
        'buttons'  => 'pos.style_buttons_word',
        'saaf'     => 'pos.style_saaf_word',
        'default'  => 'pos.style_full_word',
        // Fast Food mode (Pizza Master, 10 Aug 2026): waiter screen LAYOUT theme —
        // hides customer name / Urgent / Mazeed fold; sirf table + items + ek note
        // box + send. Visual skin = default. Fine-dine waiters pick Full/Saaf.
        'fastfood' => 'pos.style_fastfood_word',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'username',
        'password',
        'company_id',
        'role',
        'pos_role',
        'is_active',
        'dark_mode',
        'pos_personal_style', // per-user POS style: NULL = company default, 'default'/'saaf' = user's own pick (owner, 5 Aug 2026)
        'language', // PosLocale: 'en' English / 'rur' Roman Urdu / 'ur' Urdu script; NULL = company default
        'pra_reporting_enabled',
        'pos_team_password_enc',
        'pos_billing_scope',  // stream lock (07 Aug 2026); must be fillable so storeCashier User::create() persists it
    ];

    /**
     * Per-cashier PRA Reporting toggle (owner rule Jul 2026).
     * NULL = inherit the company-level flag (legacy default); a non-NULL value is
     * this user's OWN switch — one cashier flipping it never affects another.
     * Missing-column safe: a not-yet-migrated DB yields NULL → company fallback.
     */
    public function praReportingEnabled($company = null): bool
    {
        $own = $this->getAttributeValue('pra_reporting_enabled');
        if (!is_null($own)) {
            return (bool) $own;
        }
        $company = $company ?: Company::find($this->company_id);
        return (bool) ($company->pra_reporting_enabled ?? false);
    }

    /**
     * Billing Scope — EXPLICIT column value (owner request 07 Aug 2026):
     * stream lock per staff account, exactly as saved on the Team page.
     *   'both'  = dono streams (NULL/unknown values normalize here)
     *   'local' = sirf offline/local billing — PRA pipeline tak rasai nahi
     *   'pra'   = sirf PRA-reporting billing — local/provisional se door
     * Sirf pos_cashier + pos_manager par lagta hai; owner/admin hamesha 'both'.
     * Missing-column safe (PROD drift): not-yet-migrated DB → 'both'.
     *
     * Task 1186: sale-time WRITE guards, F10 provisional availability,
     * promote/retry blocks and the setCashierPra/togglePra weld-locks MUST
     * read THIS method — never the derived default below — warna Team page
     * se reporting flip 403 ho jata aur reporting-ON cashiers ka provisional
     * (F10) workflow toot jata.
     */
    public function posBillingScopeExplicit(): string
    {
        if (self::$_scopeColumnReady === null) {
            try {
                self::$_scopeColumnReady = \Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_billing_scope');
            } catch (\Throwable $e) {
                self::$_scopeColumnReady = false;
            }
        }
        if (!self::$_scopeColumnReady) {
            return 'both';
        }
        if (!in_array($this->pos_role, ['pos_cashier', 'pos_manager'], true)) {
            return 'both';
        }
        $scope = $this->getAttributeValue('pos_billing_scope');
        return in_array($scope, ['local', 'pra'], true) ? $scope : 'both';
    }

    /**
     * Billing Scope — EFFECTIVE scope (Task 1186, owner decision): har cashier
     * ko BY DEFAULT apni hi stream dikhe. A pos_cashier whose pos_billing_scope
     * column is UNSET derives the scope from their reporting status:
     * praReportingEnabled → 'pra' stream, warna 'local' stream. An explicit
     * saved value ('local'/'pra'/'both') ALWAYS wins — explicit 'both' is the
     * owner's OFF switch (aaj tak wala unrestricted view). Managers/admins
     * never derive (Task-705 manager default unchanged).
     *
     * VISIBILITY + RETURN surfaces read this (transactions/reports/dashboard/
     * day-close/todays+failed-bills APIs/read guards/return eligibility);
     * write/weld guards stay on posBillingScopeExplicit().
     */
    public function posBillingScope($company = null): string
    {
        $explicit = $this->posBillingScopeExplicit();
        if ($explicit !== 'both' || !$this->posBillingScopeIsDerived()) {
            return $explicit;
        }
        if ($this->_derivedScopeMemo === null) {
            $this->_derivedScopeMemo = $this->praReportingEnabled($company) ? 'pra' : 'local';
        }
        return $this->_derivedScopeMemo;
    }

    /**
     * Task 1186: is this account on the DERIVED default scope (no explicit
     * column value)? Own-bill exemption applies ONLY in this state — a row
     * created_by the viewer is always visible/returnable to them. Explicit
     * scopes (owner ne khud lock kiya) keep their strict behavior.
     */
    public function posBillingScopeIsDerived(): bool
    {
        if (($this->pos_role ?? null) !== 'pos_cashier') {
            return false;
        }
        if ($this->posBillingScopeExplicit() !== 'both') {
            return false;
        }
        // Explicit stored 'both' = owner's OFF switch — not derived.
        return $this->getAttributeValue('pos_billing_scope') !== 'both';
    }

    /** @var string|null — per-instance memo for the derived scope (avoids a
     *  Company::find per call when the user row inherits the company flag). */
    private ?string $_derivedScopeMemo = null;

    /** @var bool|null — class-level cache for pos_billing_scope column existence.
     *  Stored as a static class property (not a method-level static) so tests
     *  can reset it between runs via flushScopeColumnCache(). */
    private static ?bool $_scopeColumnReady = null;

    /** Reset the pos_billing_scope column-existence cache (test helper). */
    public static function flushScopeColumnCache(): void
    {
        self::$_scopeColumnReady = null;
    }

    /** @var bool|null — per-instance memo for posSalesIsolated() (avoids a
     *  Company::find + hasColumn probe per KPI query on the dashboard). */
    private ?bool $_salesIsolatedMemo = null;

    /**
     * Task 1197 — per-cashier COMPLETE sales isolation (owner-approved strict
     * rule, default ON): a pos_cashier sees ONLY bills they created themselves
     * — Transactions list, Reprint list, dashboard KPIs, provisional/failed
     * lists, read guards. SINGLE SOURCE OF TRUTH: every surface must read this
     * helper, never the column directly.
     *
     * True only for pos_role='pos_cashier' when the company switch is ON.
     * Managers / owner / confined roles are never isolated. Missing column,
     * NULL value or any error = ON (isolation is the default for every
     * company, existing and new — no backfill). Owner flips it OFF via the
     * Team-page switch (companies.pos_cashier_own_sales_only = false).
     *
     * ANDs with Billing Scope: isolation composes with (never replaces) the
     * stream lock — both predicates apply together.
     */
    public function posSalesIsolated($company = null): bool
    {
        if (($this->pos_role ?? null) !== 'pos_cashier') {
            return false;
        }
        if ($this->_salesIsolatedMemo !== null) {
            return $this->_salesIsolatedMemo;
        }
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_cashier_own_sales_only')) {
                return $this->_salesIsolatedMemo = true; // pre-migration = default ON
            }
            if (!$company) {
                $cid = app()->bound('currentCompanyId') ? app('currentCompanyId') : $this->company_id;
                $company = $cid ? \App\Models\Company::find($cid) : null;
            }
            $val = $company->pos_cashier_own_sales_only ?? null;

            return $this->_salesIsolatedMemo = ($val === null ? true : (bool) $val);
        } catch (\Throwable $e) {
            return $this->_salesIsolatedMemo = true; // fail CLOSED — isolation is the default
        }
    }

    /**
     * Billing Scope MANAGEMENT permission (owner request 07 Aug 2026): by
     * default sirf company OWNER (base role company_admin) hi staff ka scope
     * set/dekh sakta hai. Owner Team page ke switch se apne managers/admins
     * (isPosAdmin) ko bhi ijazat de sakta hai
     * (companies.billing_scope_admin_enabled). Missing-column safe.
     */
    public function canManageBillingScope($company = null): bool
    {
        if ($this->role === 'company_admin') {
            return true;
        }
        if (!$this->isPosAdmin()) {
            return false;
        }
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'billing_scope_admin_enabled')) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        if (!$company) {
            $cid = app()->bound('currentCompanyId') ? app('currentCompanyId') : $this->company_id;
            $company = $cid ? \App\Models\Company::find($cid) : null;
        }
        return (bool) ($company->billing_scope_admin_enabled ?? false);
    }

    /**
     * Task 705 — khufia "local check mode" session flag (Ctrl+Alt+Shift+L).
     * Raw flag only; toggled by PosController::toggleLocalCheck (manager/
     * owner-only endpoint). Session-based — logout/fresh login clears it.
     * VISIBILITY ONLY: PRA-reporting logic never reads this flag.
     */
    public function posLocalCheckOn(): bool
    {
        try {
            return (bool) session('pos_local_check');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Task 705 — manager default PRA-only view: a pos_manager sees ONLY the
     * PRA stream (Local tab/figures hidden everywhere) until the khufia
     * local-check mode is ON. LOCAL-scoped managers keep their local world
     * (billing-scope forcing wins); owner/admin visibility is unchanged.
     */
    public function posHidesLocalStream(): bool
    {
        return $this->isPosManager()
            && $this->posBillingScope() !== 'local'
            && !$this->posLocalCheckOn();
    }

    public function isSuperAdmin()
    {
        return $this->role === 'super_admin';
    }

    public function isCompanyAdmin()
    {
        return $this->role === 'company_admin';
    }

    public function isEmployee()
    {
        return $this->role === 'employee';
    }

    public function isViewer()
    {
        return $this->role === 'viewer';
    }

    public function isPosAdmin()
    {
        // pos_manager = full admin-equivalent inside the POS panel (owner rule Jul 2026).
        return in_array($this->pos_role, ['pos_admin', 'pos_manager'], true) || $this->role === 'company_admin';
    }

    public function isPosManager()
    {
        return $this->pos_role === 'pos_manager';
    }

    /**
     * PRA re-queue of exempt_internal bills (Tasks 808/818): owner (base role
     * company_admin) or pos_admin ONLY. pos_manager is deliberately EXCLUDED —
     * this flips a never-reported bill into the live PRA fiscal pipeline, a
     * stricter action than the general isPosAdmin() gate (which treats
     * managers as admin-equivalent). Single truth for the controller guard
     * AND both Blade surfaces (transactions list + transaction-show).
     */
    public function canRequeueExemptPra(): bool
    {
        return $this->role === 'company_admin' || $this->pos_role === 'pos_admin';
    }

    public function isPosCashier()
    {
        return $this->pos_role === 'pos_cashier';
    }

    public function isLocalViewer()
    {
        return $this->pos_role === 'local_viewer';
    }

    /**
     * Local Bills Portal — self-service viewer accounts (owner request, Task
     * 665): the company OWNER may create/manage the read-only local_viewer
     * logins himself, straight from /pos/local-bills. STRICTLY the real owner
     * (base role company_admin) — pos_manager must never see the section nor
     * pass the endpoint guard, so isPosAdmin() (which treats managers as
     * admin-equivalent) must NOT be used here. Same strictness as
     * canRequeueExemptPra(); single truth for the controller guard AND the
     * Blade section.
     */
    public function canManageLocalViewers(): bool
    {
        return $this->role === 'company_admin';
    }

    public function isPosKitchen()
    {
        return $this->pos_role === 'pos_kitchen';
    }

    public function isPosWaiter()
    {
        return $this->pos_role === 'pos_waiter';
    }

    public function isPosDelivery()
    {
        // Delivery Manager (owner, 20 Jul 2026): limit-EXEMPT team account
        // confined to the /pos/deliveries board (assign/status/settle only).
        return $this->pos_role === 'pos_delivery';
    }

    /**
     * POS Team Custom Access (Task #111): NULL = no custom set (role default
     * behavior); true/false = the custom set's verdict for this feature.
     * Confined roles + company_admin always return NULL (PosAccessService).
     */
    public function posCustomAllows(string $feature): ?bool
    {
        return \App\Services\PosAccessService::customAllows($this, $feature);
    }

    /**
     * Drop-in replacement for `isPosCashier()` in page/POST access guards:
     * still blocks cashiers by default, but lets one through when a Custom
     * Access grant covers the CURRENT request's feature (Task #111).
     * Unmapped paths keep the old cashier block unchanged.
     */
    public function posCashierBlocked(): bool
    {
        if (!$this->isPosCashier()) {
            return false;
        }
        $feature = \App\Services\PosAccessService::featureForPath(request()->path());

        return $feature === null || $this->posCustomAllows($feature) !== true;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    protected $hidden = [
        'password',
        'remember_token',
        // Encrypted copy of a POS team account's password (viewable by the
        // company's POS admin on /pos/team) — must never leak via JSON.
        'pos_team_password_enc',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'dark_mode' => 'boolean',
        ];
    }
}
