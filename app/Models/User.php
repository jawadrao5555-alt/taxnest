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
        'language', // PosLocale: 'en' English / 'rur' Roman Urdu / 'ur' Urdu script; NULL = company default
        'pra_reporting_enabled',
        'pos_team_password_enc',
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
