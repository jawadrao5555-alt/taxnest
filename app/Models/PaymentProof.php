<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProof extends Model
{
    protected $fillable = [
        'company_id',
        'amount',
        'reference',
        'payment_date',
        'proof_path',
        'status',
        'pricing_plan_id',
        'billing_cycle',
        'subscription_id',
        'notes',
        'verified_by',
        'verified_at',
        'reject_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'verified_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }
}
