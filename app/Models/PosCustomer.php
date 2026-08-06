<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosCustomer extends Model
{
    protected $fillable = [
        'company_id', 'name', 'email', 'phone', 'address',
        'city', 'ntn', 'cnic', 'type', 'is_active',
        'loyalty_points', 'loyalty_tier', 'total_spent', 'total_orders',
        'khata_balance',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'loyalty_points' => 'integer',
        'total_spent' => 'decimal:2',
        'total_orders' => 'integer',
        'khata_balance' => 'decimal:2',
    ];

    public function khataLedger()
    {
        return $this->hasMany(FbrCustomerLedger::class, 'customer_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
