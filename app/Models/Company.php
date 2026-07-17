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
        'requested_plan_id',
        'registration_no',
        'mobile',
        'city',
        'website',
        'inventory_enabled',
        'pos_restock_on_void',
        'pos_auto_purge_local_on_dayclose',
        'pos_auto_dayclose_24h',
        'pra_reporting_enabled',
        'pos_integration_mode',
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
        'pos_kds_auto_print',
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
        'pos_printer_settings',
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
        'fbr_access_code',
        'fbr_pos_token',
        'fbr_pos_environment',
        'fbr_connection_mode',
        'manager_override_pin',
        'cashier_discount_limit',
        'manager_discount_limit',
        'public_profile_slug',
        'public_profile_settings',
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
        'pos_restock_on_void' => 'boolean',
        'pos_auto_purge_local_on_dayclose' => 'boolean',
        'pos_auto_dayclose_24h' => 'boolean',
        'force_watermark' => 'boolean',
        'pra_reporting_enabled' => 'boolean',
        'fbr_pos_enabled' => 'boolean',
        'fbr_reporting_enabled' => 'boolean',
        'agent_enabled' => 'boolean',
        'agent_last_seen' => 'datetime',
        'pos_printer_settings' => 'array',
        'feature_flags' => 'array',
        'invoice_display_prefs' => 'array',
        'public_profile_settings' => 'array',
        'use_universal_pos' => 'boolean',
        'auto_print_kot' => 'boolean',
        'pos_kds_auto_print' => 'boolean',
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
            'show_cashier' => true,
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

        foreach (['show_address', 'show_ntn', 'show_email', 'show_mobile', 'show_cashier', 'show_footer'] as $k) {
            $merged[$k] = filter_var($merged[$k], FILTER_VALIDATE_BOOLEAN);
        }

        return $merged;
    }

    /**
     * POS receipt display prefs, split by receipt type (owner request Jul 2026):
     * 'pra'   = bills actually reported to PRA (fiscal POS- serials) — stored in
     *           invoice_display_prefs['pos'] (legacy key, backward compatible) with
     *           show_tax from the pos_receipt_show_tax column.
     * 'local' = L-series bills (provisionals AND reporting-OFF finals) — stored in
     *           invoice_display_prefs['pos_local']. Until the company customizes the
     *           local set, it MIRRORS the PRA set (existing behavior unchanged).
     */
    public function posReceiptPrefs(string $type = 'pra'): array
    {
        $pra = $this->displayPrefs('pos');
        $pra['show_tax'] = (bool) ($this->pos_receipt_show_tax ?? true);
        if ($type !== 'local') {
            return $pra;
        }

        $all = $this->invoice_display_prefs;
        $local = is_array($all) ? ($all['pos_local'] ?? null) : null;
        if (!is_array($local)) {
            return $pra; // never customized — mirror the PRA set
        }

        $merged = array_merge(self::defaultDisplayPrefs(), $local);
        foreach (['show_address', 'show_ntn', 'show_email', 'show_mobile', 'show_cashier', 'show_footer'] as $k) {
            $merged[$k] = filter_var($merged[$k], FILTER_VALIDATE_BOOLEAN);
        }
        $merged['show_tax'] = filter_var($local['show_tax'] ?? $pra['show_tax'], FILTER_VALIDATE_BOOLEAN);

        return $merged;
    }

    /**
     * Resolve the receipt-pref set for a specific POS transaction.
     * PRA receipt = invoice_mode 'pra' AND non-NULL pra_status (reported / queued
     * for PRA — these carry fiscal POS- serials). Everything else (deliberate
     * provisionals local/'local' AND reporting-OFF finals 'pra'+NULL, both L-series)
     * uses the Local receipt set. Mirrors the PRA-serial-split rule.
     */
    public function posReceiptPrefsFor($transaction): array
    {
        $isPra = (($transaction->invoice_mode ?? 'pra') === 'pra') && $transaction->pra_status !== null;

        return $this->posReceiptPrefs($isPra ? 'pra' : 'local');
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

    /**
     * True when this FBR POS company routes submissions through the LOCAL FBR IMS
     * fiscal component (localhost:8524) via the Desktop Sync Agent, instead of the
     * (now-retired) cloud bulk PostData API. Single predicate used everywhere so
     * there is no PRA-vs-FBR ambiguity in the shared Agent API.
     */
    public function agentServesFbr(): bool
    {
        return (bool) $this->fbr_pos_enabled
            && ($this->fbr_connection_mode ?? 'cloud') === 'fiscal_device';
    }

    /**
     * Normalized silent-print settings (Desktop Sync Agent printer routing).
     * Always returns the full shape with defaults so views/controllers never
     * null-check individual keys.
     */
    public function printerSettings(): array
    {
        $s = $this->pos_printer_settings ?? [];
        return [
            'silent_print_enabled' => (bool) ($s['silent_print_enabled'] ?? false),
            'receipt_printer' => $s['receipt_printer'] ?? null,
            'kot_printer' => $s['kot_printer'] ?? null,
            'available_printers' => is_array($s['available_printers'] ?? null) ? $s['available_printers'] : [],
            'printers_reported_at' => $s['printers_reported_at'] ?? null,
        ];
    }

    /**
     * True when the Desktop Sync Agent has heartbeat within the last 2 minutes.
     * Used to decide silent-print vs popup fallback at enqueue time.
     */
    public function agentOnline(): bool
    {
        return (bool) $this->agent_enabled
            && $this->agent_last_seen
            && $this->agent_last_seen->gt(now()->subMinutes(2));
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

    /**
     * True when PRA reporting is in play for ANY account of this company —
     * the company-level flag OR any user's personal per-cashier toggle.
     * Used by user-less contexts (offline sync job, PraIntegrationService)
     * and the NTN-clearing guard. Missing-column safe (pre-migration prod).
     */
    public function praReportingActive(): bool
    {
        if ($this->pra_reporting_enabled) {
            return true;
        }
        try {
            return $this->users()->where('pra_reporting_enabled', 1)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The package the shop picked at POS registration — shown to the admin
     * at approval time; approval assigns a 1-year subscription of this plan.
     */
    public function requestedPlan()
    {
        return $this->belongsTo(PricingPlan::class, 'requested_plan_id');
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
