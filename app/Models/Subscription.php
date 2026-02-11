<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'company_id',
        'pricing_plan_id',
        'start_date',
        'end_date',
        'trial_ends_at',
        'active'
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function isTrialActive(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isTrialExpired(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function isExpired(): bool
    {
        return $this->end_date && \Carbon\Carbon::parse($this->end_date)->isPast();
    }

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
