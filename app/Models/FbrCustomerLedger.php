<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FBR POS Udhaar/Khata ledger (Aug 2026 — Retail Core).
 * entry_type: udhaar (credit sale, balance UP) · wasooli (payment received,
 * balance DOWN) · return_adjust (return refunded into khata, balance DOWN).
 * pos_customers.khata_balance is the cached running balance — ALWAYS update
 * it in the same DB transaction as the ledger insert.
 */
class FbrCustomerLedger extends Model
{
    protected $fillable = [
        'company_id', 'customer_id', 'entry_type', 'amount', 'balance_after',
        'transaction_id', 'note', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(PosCustomer::class, 'customer_id');
    }

    public function transaction()
    {
        return $this->belongsTo(FbrPosTransaction::class, 'transaction_id');
    }
}
