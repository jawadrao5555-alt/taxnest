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
        'shop_lat',
        'shop_lng',
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
        'referred_by_user_id',
        'referral_code_used',
        'registration_no',
        'mobile',
        'city',
        'shop_lat',
        'shop_lng',
        'website',
        'inventory_enabled',
        'pos_restock_on_void',
        'pos_auto_purge_local_on_dayclose',
        'pos_auto_dayclose_24h',
        'order_match_style',
        'pos_token_counter',
        'pos_token_date',
        'pra_number_style',
        'local_number_style',
        'bill_token_counter_pra',
        'bill_token_date_pra',
        'bill_token_counter_local',
        'bill_token_date_local',
        'pos_cashier_dayclose',
        'pos_cash_received_enabled',
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
        'default_language', // PosLocale: 'en' / 'rur' Roman Urdu / 'ur' Urdu script — company-wide default
        'pos_dashboard_style',
        'kitchen_printer_enabled',
        'print_on_hold',
        'print_on_pay',
        'dine_in_auto_kot',
        'pos_kot_full_mode',
        'kot_compact',
        'kot_show_customer',
        'kot_show_orderby',
        'kot_show_barcode',
        'kot_show_footer',
        'kot_align_center',
        'kot_left_margin_mm',
        'auto_print_kot',
        'pos_kds_auto_print',
        'kot_reprint_enabled',
        'pos_receipt_show_tax',
        'pos_guided_flow_enabled',
        'pos_tax_rate_cash',
        'pos_tax_rate_card',
        'pos_tax_inclusive',
        'pos_tax_pricing_mode',
        'pos_product_search_mode',
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
        'agent_offline_mode',
        'agent_snapshot_at',
        'agent_enabled',
        'agent_submits_pra',
        'receipt_printer_size',
        'pos_printer_settings',
        'confidential_pin',
        'next_local_invoice_number',
        'logo_path',
        'print_paper_size',
        'receipt_footer_note',
        'invoice_display_prefs',
        'di_branding',
        'status',
        'product_type',
        'franchise_id',
        'agent_id',
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
        'pos_cashier_dayclose' => 'boolean',
        'force_watermark' => 'boolean',
        'pra_reporting_enabled' => 'boolean',
        'pos_tax_inclusive' => 'boolean',
        'fbr_pos_enabled' => 'boolean',
        'fbr_reporting_enabled' => 'boolean',
        'agent_enabled' => 'boolean',
        'agent_submits_pra' => 'boolean',
        'agent_last_seen' => 'datetime',
        'agent_offline_mode' => 'boolean',
        'agent_snapshot_at' => 'datetime',
        'pos_printer_settings' => 'array',
        'feature_flags' => 'array',
        'invoice_display_prefs' => 'array',
        'di_branding' => 'array',
        'public_profile_settings' => 'array',
        'use_universal_pos' => 'boolean',
        'auto_print_kot' => 'boolean',
        'dine_in_auto_kot' => 'boolean',
        'pos_kot_full_mode' => 'boolean',
        'kot_compact' => 'boolean',
        'kot_show_customer' => 'boolean',
        'kot_show_orderby' => 'boolean',
        'kot_show_barcode' => 'boolean',
        'kot_show_footer' => 'boolean',
        'kot_align_center' => 'boolean',
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
            // ZFC 28 Jul 2026: business name + "Developed by" line are now
            // per-receipt-type toggles (owner wanted them OFF on local bills).
            'show_business_name' => true,
            'show_developed_by' => true,
            'footer_text' => null,
        ];
    }

    public function displayPrefs(string $product): array
    {
        $defaults = self::defaultDisplayPrefs();

        $all = $this->invoice_display_prefs;
        $prefs = is_array($all) ? ($all[$product] ?? []) : [];

        $merged = array_merge($defaults, is_array($prefs) ? $prefs : []);

        foreach (['show_address', 'show_ntn', 'show_email', 'show_mobile', 'show_cashier', 'show_footer', 'show_business_name', 'show_developed_by'] as $k) {
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
        foreach (['show_address', 'show_ntn', 'show_email', 'show_mobile', 'show_cashier', 'show_footer', 'show_business_name', 'show_developed_by'] as $k) {
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

    /**
     * POS receipt PRINT STYLE (customer feedback Jul 2026 — Pizza Master):
     * global like paper size (it's the printer/brand look, not a bill-type
     * setting). Stored in invoice_display_prefs['pos_style'].
     * - bold: whole receipt prints in bold/dark (like the KOT font) — some
     *   thermal heads print the plain font too thin. Default ON for everyone
     *   (owner decision 21 Jul 2026: "universal kr do") — a company that
     *   prefers the thin "plain drafting" look can still switch it OFF on
     *   Receipt Settings (explicit saved false is respected).
     * - logo: 'center' (large centered logo above the name, like classic
     *   printed bills — the new universal default) or 'side' (compact, next
     *   to business name). Companies without a logo just get the plain name
     *   header (blade guards on $logoDataUri).
     */
    public function posReceiptStyle(): array
    {
        $all = $this->invoice_display_prefs;
        $style = is_array($all) ? ($all['pos_style'] ?? []) : [];
        if (!is_array($style)) { $style = []; }

        return [
            'bold' => filter_var($style['bold'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'logo' => in_array(($style['logo'] ?? 'center'), ['side', 'center'], true) ? ($style['logo'] ?? 'center') : 'center',
            // show_logo (Task #292): master switch — when false the logo NEVER prints
            // on any receipt. Default true = existing behaviour (logo on every bill
            // that has one uploaded). Existing companies with no saved value get true.
            'show_logo' => filter_var($style['show_logo'] ?? true, FILTER_VALIDATE_BOOLEAN),
            // logo_finals_only: sub-option under show_logo — when true the logo is
            // suppressed on local/provisional bills (invoice_mode='local') and printed
            // only on final/PRA bills. Only meaningful when show_logo is true.
            // Default false = logo on every bill (unchanged behaviour).
            'logo_finals_only' => filter_var($style['logo_finals_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // show_menu_qr (Task #292): when false, NEITHER the public-profile Menu QR
            // nor the invoice JSON fallback QR prints on non-fiscal receipts.
            // The PRA Sahulat fiscal QR (pra_status='submitted') is NEVER affected.
            // Default true = existing behaviour (QR always renders).
            'show_menu_qr' => filter_var($style['show_menu_qr'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
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
     * POS-CONFIG REVISION (Task 52, Jul 2026): explicit whitelist hash of the
     * company fields that actually shape the sale screen / receipts / POS
     * behaviour. The boot fingerprint ('set' key in PosController::
     * posBootFingerprint) used to hash the raw companies.updated_at, so ANY
     * frequent writer to the companies row (agent heartbeats, counters, sync
     * telemetry) silently recreated the "NestPOS bar bar load" reload loop.
     * Telemetry/counter columns (agent_last_seen, agent_version,
     * agent_snapshot_at, next_*_invoice_number, compliance_score, token
     * expiries, ...) are deliberately ABSENT from this list — adding a new
     * volatile column can never break the fingerprint again.
     *
     * When you add a NEW company column that the sale screen bakes in,
     * add it here so a settings change still refreshes cached screens.
     */
    public function posConfigRev(): string
    {
        $cols = [
            // Identity / receipt header
            'name', 'ntn', 'phone', 'mobile', 'address', 'city', 'logo_path',
            'invoice_number_prefix', 'receipt_footer_note', 'pos_receipt_show_tax',
            'print_paper_size', 'receipt_printer_size',
            // Status gates
            'status', 'company_status', 'suspended_at', 'pos_setup_completed',
            // Look & behaviour
            'pos_theme', 'pos_dashboard_style', 'pos_ui_density', 'use_universal_pos',
            'pos_guided_flow_enabled', 'pos_quick_type_enabled', 'default_language',
            'pos_receipt_autoclose_seconds', 'invoice_display_prefs', 'feature_flags',
            'pos_cash_received_enabled',
            // Tax / pricing
            'standard_tax_rate', 'pos_tax_rate_cash', 'pos_tax_rate_card',
            'pos_tax_inclusive', 'pos_tax_pricing_mode', 'pos_product_search_mode',
            // Reporting / integration modes
            'pra_reporting_enabled', 'pos_integration_mode', 'pra_environment',
            'pra_pos_id', 'pra_connection_mode', 'agent_enabled', 'agent_submits_pra',
            'fbr_pos_enabled', 'fbr_universal_enabled', 'fbr_reporting_enabled',
            'fbr_pos_id', 'fbr_pos_environment', 'fbr_connection_mode',
            // Inventory / restaurant / printing features
            'inventory_enabled', 'pos_restock_on_void', 'restaurant_mode',
            'pos_use_legacy_restaurant', 'kds_enabled', 'pos_kds_auto_print',
            'kitchen_printer_enabled', 'print_on_hold', 'print_on_pay',
            'auto_print_kot', 'kot_reprint_enabled', 'dine_in_auto_kot',
            'pos_kot_full_mode', 'kot_compact', 'kot_show_customer',
            'kot_show_orderby', 'kot_show_barcode', 'kot_show_footer',
            'kot_align_center', 'kot_left_margin_mm',
            // Day-close / limits / pins
            'pos_auto_purge_local_on_dayclose', 'pos_auto_dayclose_24h',
            'pos_dayclose_final_local_action', 'pos_dayclose_provisional_action',
            'pos_customer_spend_persist', 'cashier_discount_limit',
            'manager_discount_limit', 'manager_override_pin',
            // Bill Number Style (07 Aug 2026): receipt number display per stream —
            // a settings change must refresh cached sale screens.
            'pra_number_style', 'local_number_style',
        ];

        $vals = [];
        foreach ($cols as $c) {
            $v = $this->getAttribute($c);
            // Normalize objects (Carbon, casts) into stable scalars.
            if ($v instanceof \DateTimeInterface) {
                $v = $v->format('Y-m-d H:i:s');
            }
            $vals[$c] = $v;
        }

        // pos_printer_settings holds BOTH cashier-chosen routing (relevant) and
        // agent-reported telemetry (available_printers list + reported-at beat,
        // rewritten every ~5 min by reportPrinters). Hash only the deliberate
        // routing keys, or every printer report would fake a "settings change".
        $ps = $this->printerSettings();
        $vals['printer_routing'] = [
            'silent_print_enabled' => $ps['silent_print_enabled'],
            'receipt_printer' => $ps['receipt_printer'],
            'kot_printer' => $ps['kot_printer'],
        ];

        return md5(json_encode($vals));
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
            // Counter KOT Copy (owner request 30 Jul 2026): DINE-IN orders only —
            // every KOT also prints ONE full copy on this counter printer when
            // the tick is ON. Other order types never use it.
            'counter_kot_printer' => $s['counter_kot_printer'] ?? null,
            'counter_kot_enabled' => (bool) ($s['counter_kot_enabled'] ?? false),
            'available_printers' => is_array($s['available_printers'] ?? null) ? $s['available_printers'] : [],
            'printers_reported_at' => $s['printers_reported_at'] ?? null,
            // One-click silent-print prompt (Jul 2026): timestamp when an admin
            // dismissed the sale-screen banner OR manually saved printer settings
            // (deliberate choice either way — never nag again). MUST stay in this
            // normalized shape: the printer-settings POST rebuilds $settings from
            // it and would silently drop the key otherwise.
            'prompt_dismissed_at' => $s['prompt_dismissed_at'] ?? null,
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

    /**
     * True when PRA SUBMISSION is routed through the Desktop Sync Agent
     * ("Agent Sync" mode). False = "Direct Production" — the server submits
     * to PRA itself.
     *
     * DECOUPLED from agent_enabled on purpose (Jul 2026): agent_enabled means
     * "the agent may connect" (auth + heartbeat + SILENT PRINTING). A shop can
     * run Direct Production for PRA submission while still using the agent for
     * silent receipt/KOT printing. Missing-column safe (pre-migration prod:
     * falls back to legacy agent_enabled-only behavior).
     *
     * Fiscal Device mode ALWAYS routes via the agent — the cloud PostData API
     * rejects new POS IDs with Code 112, so the server must never direct-submit.
     */
    public function agentHandlesPra(): bool
    {
        if (($this->pra_connection_mode ?? 'cloud') === 'fiscal_device') {
            return true;
        }
        if (!$this->agent_enabled) {
            return false;
        }
        try {
            return (bool) ($this->agent_submits_pra ?? true);
        } catch (\Throwable $e) {
            return true;
        }
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

    public function agent()
    {
        return $this->belongsTo(Agent::class);
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
     * Effective POS tax pricing mode: 'exclusive' | 'inclusive' | 'inclusive_card_save'.
     * NULL / unknown / pre-migration column derives from the legacy
     * pos_tax_inclusive boolean (which stays SYNCED — 1 for both inclusive
     * variants — so every existing snapshot branch keeps working).
     */
    public function posTaxPricingMode(): string
    {
        $mode = null;
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_tax_pricing_mode')) {
            $mode = $this->pos_tax_pricing_mode;
        }
        if (in_array($mode, ['exclusive', 'inclusive', 'inclusive_card_save'], true)) {
            return $mode;
        }
        return ($this->pos_tax_inclusive ?? false) ? 'inclusive' : 'exclusive';
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

    /**
     * Base64 data-URI of the company logo, SAFE for thermal receipts.
     *
     * Large uploaded logos (multi-MB photos) must NEVER be embedded as-is:
     * the Desktop Agent prints receipts by loading the whole HTML as a
     * base64 data: URL, and Chromium rejects URLs over ~2 MB with
     * ERR_INVALID_URL — the shop sees "sent to printer" but nothing prints.
     * Big embeds also bloat every receipt popup/PDF over shop internet.
     *
     * Strategy: files up to 120 KB embed unchanged; bigger files are
     * downscaled once to a cached 384px-wide PNG (thermal printers are
     * ~576 dots wide, receipts show the logo at ≤32mm, so 384px is plenty)
     * and the cached copy is embedded. If the image can't be processed,
     * return null — a receipt without a logo beats a receipt that never
     * prints.
     */
    public function receiptLogoDataUri(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }
        $file = public_path('storage/' . $this->logo_path);
        if (!is_file($file)) {
            $file = storage_path('app/public/' . $this->logo_path);
        }
        if (!is_file($file)) {
            return null;
        }

        $size = @filesize($file);
        if ($size !== false && $size <= 120 * 1024) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $mime = $ext === 'jpg' ? 'jpeg' : ($ext ?: 'png');
            return 'data:image/' . $mime . ';base64,' . base64_encode((string) file_get_contents($file));
        }

        // Cached downscaled copy — keyed on path + mtime so a re-uploaded
        // logo regenerates automatically.
        $cacheDir = storage_path('app/public/receipt-logos');
        $cacheFile = $cacheDir . '/' . $this->id . '-' . substr(md5($this->logo_path . '|' . (@filemtime($file) ?: 0)), 0, 10) . '.png';

        if (!is_file($cacheFile)) {
            if (!function_exists('imagecreatefromstring')) {
                return null; // no GD on this host — skip logo rather than break printing
            }
            try {
                $src = @imagecreatefromstring((string) file_get_contents($file));
                if (!$src) {
                    return null;
                }
                $w = imagesx($src);
                $h = imagesy($src);
                if ($w < 1 || $h < 1) {
                    imagedestroy($src);
                    return null;
                }
                $tw = min(384, $w);
                $th = max(1, (int) round($h * $tw / $w));
                $dst = imagecreatetruecolor($tw, $th);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0755, true);
                }
                imagepng($dst, $cacheFile, 8);
                imagedestroy($src);
                imagedestroy($dst);
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (!is_file($cacheFile)) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($cacheFile));
    }
}
