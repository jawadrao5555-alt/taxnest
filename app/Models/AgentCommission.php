<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Agent commission ledger line. Earn lines (type new/renewal) are frozen at
 * payment-verification time with the rate then in force; clawback lines are
 * negative adjustments recorded on refund/reversal.
 */
class AgentCommission extends Model
{
    protected $fillable = [
        'agent_id',
        'company_id',
        'company_name',
        'payment_proof_id',
        'type',
        'base_amount',
        'rate_percent',
        'amount',
        'period_month',
        'description',
        'status',
        'paid_at',
        'paid_by_admin_id',
        'created_by_admin_id',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'rate_percent' => 'decimal:2',
        'amount' => 'decimal:2',
        'period_month' => 'date',
        'paid_at' => 'datetime',
    ];

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function paymentProof()
    {
        return $this->belongsTo(PaymentProof::class);
    }
}
