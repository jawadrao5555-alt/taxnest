<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A bank account the organisation actually holds (Task 1552).
 *
 * Mapped onto its own ledger account rather than sharing one: two accounts at
 * two banks that post into a single "Bank" line cannot be reconciled against
 * either statement, which is exactly when the reconciliation screen stops being
 * used.
 *
 * Nothing here authorises a payment. The account number is a reference the
 * accountant reads off a statement, never a credential.
 */
class HealthBankAccount extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'health_account_id',
        'title',
        'bank_name',
        'account_no',
        'iban',
        'branch_name',
        'opening_balance',
        'opening_balance_date',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'opening_balance_date' => 'date',
        'is_active' => 'boolean',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_account_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function account()
    {
        return $this->belongsTo(HealthAccount::class, 'health_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
