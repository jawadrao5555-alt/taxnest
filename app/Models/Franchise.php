<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class Franchise extends Authenticatable
{
    protected $fillable = ['name', 'email', 'phone', 'commission_rate', 'status', 'password'];

    protected $hidden = ['password'];

    protected $casts = [
        'commission_rate' => 'float',
        'password' => 'hashed',
    ];

    public function companies()
    {
        return $this->hasMany(Company::class, 'franchise_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
