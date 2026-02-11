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
        'token_expires_at',
        'compliance_score',
        'fbr_environment',
        'fbr_sandbox_token',
        'fbr_production_token',
        'fbr_registration_no',
        'fbr_business_name',
        'suspended_at'
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    protected $hidden = [
        'fbr_sandbox_token',
        'fbr_production_token',
    ];

    public function isSuspended()
    {
        return $this->suspended_at !== null;
    }

    public function getActiveFbrTokenAttribute()
    {
        if ($this->fbr_environment === 'production') {
            return $this->fbr_production_token;
        }
        return $this->fbr_sandbox_token;
    }

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

    public function complianceScores()
    {
        return $this->hasMany(ComplianceScore::class);
    }

    public function anomalyLogs()
    {
        return $this->hasMany(AnomalyLog::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function complianceReports()
    {
        return $this->hasMany(ComplianceReport::class);
    }

    public function vendorRiskProfiles()
    {
        return $this->hasMany(VendorRiskProfile::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
