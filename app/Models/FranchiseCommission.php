<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranchiseCommission extends Model
{
    protected $fillable = [
        'franchise_id', 'company_id', 'company_name', 'payment_proof_id',
        'base_amount', 'rate_percent', 'amount', 'status', 'earned_at',
        'paid_at', 'paid_by_admin_id', 'payout_reference', 'type',
        'source_commission_id', 'adjustment_reason', 'payout_id',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2', 'rate_percent' => 'decimal:2',
        'amount' => 'decimal:2', 'earned_at' => 'datetime', 'paid_at' => 'datetime',
    ];
}
