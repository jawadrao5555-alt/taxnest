<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProof extends Model
{
    protected $fillable = [
        'company_id',
        'amount',
        'payment_method',
        'auto_access_until',
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
        'file_pruned_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'auto_access_until' => 'datetime',
        'verified_at' => 'datetime',
        'file_pruned_at' => 'datetime',
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
