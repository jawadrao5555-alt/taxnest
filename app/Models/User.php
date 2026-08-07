<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

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
     * Billing Scope (owner request 07 Aug 2026): stream lock per staff account.
     *   'both'  = dono streams (default; NULL/unknown values normalize here)
     *   'local' = sirf offline/local billing — PRA pipeline tak rasai nahi
     *   'pra'   = sirf PRA-reporting billing — local/provisional se door
     * Sirf pos_cashier + pos_manager par lagta hai; owner/admin hamesha 'both'.
     * Missing-column safe (PROD drift): not-yet-migrated DB → 'both'.
     */
    public function posBillingScope(): string
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

    /** @var bool|null — class-level cache for pos_billing_scope column existence.
     *  Stored as a static class property (not a method-level static) so tests
     *  can reset it between runs via flushScopeColumnCache(). */
    private static ?bool $_scopeColumnReady = null;

    /** Reset the pos_billing_scope column-existence cache (test helper). */
    public static function flushScopeColumnCache(): void
    {
        self::$_scopeColumnReady = null;
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

    public function isPosCashier()
    {
        return $this->pos_role === 'pos_cashier';
    }

    public function isLocalViewer()
    {
        return $this->pos_role === 'local_viewer';
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
