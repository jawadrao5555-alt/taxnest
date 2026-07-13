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
        'pra_reporting_enabled',
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
        return $this->pos_role === 'pos_admin' || $this->role === 'company_admin';
    }

    public function isPosCashier()
    {
        return $this->pos_role === 'pos_cashier';
    }

    public function isLocalViewer()
    {
        return $this->pos_role === 'local_viewer';
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    protected $hidden = [
        'password',
        'remember_token',
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
