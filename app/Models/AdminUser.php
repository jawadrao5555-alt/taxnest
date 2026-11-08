<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function canManageBilling(): bool
    {
        // Preserve the established default admin role as well as the owner.
        // Support/viewer and unmapped roles are not financial write grants.
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    public function canManageCompanyLifecycle(): bool
    {
        // Company rejection/suspension/bin/purge changes customer access or
        // destroys operational rows.  Keep the established administrator
        // roles, but never let support/viewer/unmapped roles inherit that
        // authority merely because they have an admin-panel session.
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    public function canManageRelease(): bool
    {
        // Publishing customer notices and changing tutorial visibility/gates
        // changes the customer-facing release.  A support/viewer session may
        // inspect history, but it is not release authority.
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    public static function requireBillingAdministrator(): self
    {
        $actor = auth('admin')->user();
        $current = $actor instanceof self ? $actor->fresh() : null;
        abort_unless($current && $current->canManageBilling(), 403, 'Billing changes require an administrator.');

        return $current;
    }

    public static function requireCompanyLifecycleAdministrator(): self
    {
        $actor = auth('admin')->user();
        $current = $actor instanceof self ? $actor->fresh() : null;
        abort_unless($current && $current->canManageCompanyLifecycle(), 403, 'Company lifecycle changes require an administrator.');

        return $current;
    }

    public static function requireReleaseAdministrator(): self
    {
        $actor = auth('admin')->user();
        $current = $actor instanceof self ? $actor->fresh() : null;
        abort_unless($current && $current->canManageRelease(), 403, 'Release changes require an administrator.');

        return $current;
    }
}
