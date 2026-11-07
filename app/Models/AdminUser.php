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

    public static function requireBillingAdministrator(): self
    {
        $actor = auth('admin')->user();
        $current = $actor instanceof self ? $actor->fresh() : null;
        abort_unless($current && $current->canManageBilling(), 403, 'Billing changes require an administrator.');

        return $current;
    }
}
