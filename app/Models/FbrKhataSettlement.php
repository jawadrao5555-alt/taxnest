<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A FIFO allocation from a payment/return adjustment to one credit-sale lot.
 *
 * The ledger remains the financial source of truth. These rows make the
 * "which bill did this partial wasooli clear?" answer durable and auditable.
 */
class FbrKhataSettlement extends Model
{
    protected $fillable = [
        'company_id',
        'customer_id',
        'settlement_ledger_id',
        'credit_ledger_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function settlement()
    {
        return $this->belongsTo(FbrCustomerLedger::class, 'settlement_ledger_id');
    }

    public function credit()
    {
        return $this->belongsTo(FbrCustomerLedger::class, 'credit_ledger_id');
    }
}