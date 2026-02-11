<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'ntn',
        'email',
        'phone',
        'address',
        'fbr_token',
        'compliance_score'
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)->where('active', true)->with('pricingPlan');
    }
}
