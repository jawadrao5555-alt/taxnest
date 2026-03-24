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
    ];

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
