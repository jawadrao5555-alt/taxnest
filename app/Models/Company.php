<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

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
        'kds_enabled',
        'restaurant_mode',
        'pos_type',
        'business_category',
        'feature_flags',
        'use_universal_pos',
        'pos_ui_density',
        'pos_theme',
        'pos_dashboard_style',
        'kitchen_printer_enabled',
        'print_on_hold',
        'print_on_pay',
        'auto_print_kot',
        'kot_reprint_enabled',
        'pos_receipt_show_tax',
        'pos_guided_flow_enabled',
        'pos_tax_rate_cash',
        'pos_tax_rate_card',
        'pos_setup_completed',
        'pos_use_legacy_restaurant',
        'fbr_universal_enabled',
        'pra_environment',
        'pra_pos_id',
        'pra_access_code',
        'pra_production_token',
        'pra_proxy_url',
        'pra_connection_mode',
        'agent_api_key',
        'agent_last_seen',
        'agent_version',
        'agent_enabled',
        'receipt_printer_size',
        'confidential_pin',
        'next_local_invoice_number',
        'logo_path',
        'print_paper_size',
        'receipt_footer_note',
        'invoice_display_prefs',
        'status',
        'product_type',
        'franchise_id',
        'deleted_reason',
        'force_watermark',
        'fbr_pos_enabled',
        'fbr_reporting_enabled',
        'fbr_pos_id',
        'fbr_pos_token',
        'fbr_pos_environment',
        'manager_override_pin',
        'cashier_discount_limit',
        'manager_discount_limit',
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
        'force_watermark' => 'boolean',
        'pra_reporting_enabled' => 'boolean',
        'fbr_pos_enabled' => 'boolean',
        'fbr_reporting_enabled' => 'boolean',
        'agent_enabled' => 'boolean',
        'agent_last_seen' => 'datetime',
        'feature_flags' => 'array',
        'invoice_display_prefs' => 'array',
        'use_universal_pos' => 'boolean',
        'auto_print_kot' => 'boolean',
        'kot_reprint_enabled' => 'boolean',
        'pos_receipt_show_tax' => 'boolean',
        'pos_guided_flow_enabled' => 'boolean',
        'pos_setup_completed' => 'boolean',
        'pos_use_legacy_restaurant' => 'boolean',
        'fbr_universal_enabled' => 'boolean',
    ];

    protected $hidden = [
        'fbr_sandbox_token',
        'fbr_production_token',
        'confidential_pin',
        'manager_override_pin',
    ];

    /**
     * Receipt / invoice display preferences for a product ('pos' or 'di').
     * NULL / missing keys = show everything with default footer text,
     * so companies that never touched the setting see zero change.
     */
    public static function defaultDisplayPrefs(): array
    {
        return [
            'show_address' => true,
            'show_ntn' => true,
            'show_email' => true,
            'show_mobile' => true,
            'show_footer' => true,
            'footer_text' => null,
        ];
    }

    public function displayPrefs(string $product): array
    {
        $defaults = self::defaultDisplayPrefs();

        $all = $this->invoice_display_prefs;
        $prefs = is_array($all) ? ($all[$product] ?? []) : [];

        $merged = array_merge($defaults, is_array($prefs) ? $prefs : []);

        foreach (['show_address', 'show_ntn', 'show_email', 'show_mobile', 'show_footer'] as $k) {
            $merged[$k] = filter_var($merged[$k], FILTER_VALIDATE_BOOLEAN);
        }

        return $merged;
    }

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

    public function franchise()
    {
        return $this->belongsTo(Franchise::class);
    }

    public function usageStats()
    {
        return $this->hasOne(CompanyUsageStat::class);
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

    public function paymentProofs()
    {
        return $this->hasMany(PaymentProof::class);
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
