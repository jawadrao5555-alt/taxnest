<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'ntn',
        'cnic',
        'phone',
        'email',
        'address',
        'city',
        'contact_person',
        'notes',
        'is_active',
        // Day-one payable carried over from whatever the hospital used before
        // the panel. Fillable on purpose — Eloquent drops a non-fillable write
        // without a word, and a silently dropped opening balance reads as
        // "we owe this distributor nothing".
        'opening_balance',
        'opening_balance_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'opening_balance' => 'float',
        'opening_balance_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** Task 1580: distributor ledger relations. */
    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function purchaseReturns()
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
