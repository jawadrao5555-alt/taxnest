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
        'active'
    ];

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
