<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'owner_name',
        'ntn',
        'cnic',
        'email',
        'phone',
        'address',
        'business_activity',
        'fbr_token',
        'token_expires_at',
        'compliance_score',
        'fbr_environment',
        'fbr_sandbox_token',
        'fbr_production_token',
        'fbr_registration_no',
        'fbr_business_name',
        'suspended_at',
        'company_status',
        'token_expiry_date',
        'last_successful_submission',
        'fbr_connection_status',
        'fbr_sandbox_url',
        'fbr_production_url',
        'is_internal_account',
        'onboarding_completed',
        'standard_tax_rate',
        'sector_type',
        'province',
        'invoice_number_prefix',
        'next_invoice_number',
        'invoice_limit_override',
        'user_limit_override',
        'branch_limit_override',
        'registration_no',
        'mobile',
        'city',
        'website',
        'inventory_enabled',
        'pra_reporting_enabled',
        'pra_environment',
        'pra_pos_id',
        'pra_production_token',
        'receipt_printer_size',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'suspended_at' => 'datetime',
        'token_expiry_date' => 'date',
        'last_successful_submission' => 'datetime',
        'is_internal_account' => 'boolean',
        'onboarding_completed' => 'boolean',
        'standard_tax_rate' => 'float',
        'inventory_enabled' => 'boolean',
        'pra_reporting_enabled' => 'boolean',
    ];

    protected $hidden = [
        'fbr_sandbox_token',
        'fbr_production_token',
    ];

    public function isSuspended()
    {
        return $this->company_status === 'suspended';
    }

    public function isActive()
    {
        return $this->company_status === 'active';
    }

    public function isPending()
    {
        return $this->company_status === 'pending';
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

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(CustomerLedger::class);
    }

    public function customerTaxRules()
    {
        return $this->hasMany(CustomerTaxRule::class);
    }

    public function getStandardTaxRateValue(): float
    {
        return $this->standard_tax_rate ?? 18.0;
    }
}
