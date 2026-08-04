<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Models\PosDayCloseReport;
use App\Models\PosProduct;
use App\Models\PosCustomer;
use App\Models\PosService;
use App\Models\PosDeal;
use App\Models\PosDealItem;
use App\Models\PosTerminal;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\PosPayment;
use App\Models\PosTaxRule;
use App\Models\PraLog;
use App\Models\RestaurantTable;
use App\Models\RestaurantOrder;
use App\Models\ProductRecipe;
use App\Models\Ingredient;
use App\Services\PraIntegrationService;
use App\Services\PosFeatureService;
use App\Support\PosPaymentBuckets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function updateTheme(Request $request)
    {
        $theme = $request->input('theme', 'purple');
        $allowed = ['purple', 'blue', 'emerald', 'orange', 'midnight', 'rose'];
        if (!in_array($theme, $allowed)) {
            return response()->json(['success' => false, 'message' => __('pos.invalid_theme')], 422);
        }
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_theme' => $theme]);
        return response()->json(['success' => true, 'theme' => $theme]);
    }

    public function updateDashboardStyle(Request $request)
    {
        $user = auth('pos')->user();
        $isAdmin = in_array($user->pos_role ?? $user->role ?? '', ['pos_admin', 'pos_manager', 'company_admin']);
        if (!$isAdmin) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_dashboard_style')], 403);
        }
        $style = $request->json('style') ?? $request->input('style', 'default');
        $allowed = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify', 'saaf'];
        if (!in_array($style, $allowed)) {
            return response()->json(['success' => false, 'message' => __('pos.invalid_style')], 422);
        }
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_dashboard_style' => $style]);
        return response()->json(['success' => true, 'style' => $style]);
    }

    public function updateGuidedFlow(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        $enabled = $request->boolean('enabled');
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_guided_flow_enabled' => $enabled]);
        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    /**
     * Quick Type Mode toggle — admin-only, OPT-IN (default OFF).
     * Owner 22 Jul 2026: customers found the sale-screen "Quick" button
     * cluttering; dhaba/food shops that want it enable it here.
     */
    public function updateQuickType(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        $enabled = $request->boolean('enabled');
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_quick_type_enabled' => $enabled]);
        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    /**
     * Receipt popup auto-close timer — admin-only (owner, 23 Jul 2026).
     * The sale-screen success popup closes itself after N seconds; any
     * cashier interaction pauses/cancels the countdown. 0 = never
     * (persistent popup, the old behavior); NULL = platform default (10s).
     */
    public function updateReceiptAutoclose(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        // Prod schema drift guard — never pretend to save into a missing column.
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_receipt_autoclose_seconds')) {
            return response()->json(['success' => false, 'message' => __('pos.setting_not_available_yet')], 503);
        }
        $secs = (int) $request->input('seconds', 10);
        if (!in_array($secs, [0, 5, 10, 15, 20, 30], true)) {
            return response()->json(['success' => false, 'message' => __('pos.invalid_value')], 422);
        }
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_receipt_autoclose_seconds' => $secs]);
        return response()->json(['success' => true, 'seconds' => $secs]);
    }

    /**
     * Tax-Inclusive Pricing (Menu-Rate-Final) mode toggle — admin-only.
     * Applies to NEW bills only: existing bills keep their own tax_inclusive
     * snapshot, so history/reports/PRA payloads never shift retroactively.
     */
    public function updateTaxPricingMode(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        // Prod schema drift guard: never accept the switch if the column is
        // missing — bills would silently store inclusive prices under
        // exclusive semantics (wrong tax to PRA).
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_tax_inclusive')) {
            return response()->json(['success' => false, 'message' => __('pos.setting_not_available_yet')], 503);
        }
        // Three modes (owner Jul 2026): 'exclusive' | 'inclusive' | 'inclusive_card_save'.
        // Back-compat: older clients send {"inclusive": bool} only.
        $mode = $request->input('mode');
        if (!in_array($mode, ['exclusive', 'inclusive', 'inclusive_card_save'], true)) {
            $mode = $request->boolean('inclusive') ? 'inclusive' : 'exclusive';
        }
        $modeColumnExists = \Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_tax_pricing_mode');
        if ($mode === 'inclusive_card_save'
            && (!$modeColumnExists || !\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_menu_rate'))) {
            return response()->json(['success' => false, 'message' => __('pos.mode_not_available_yet')], 503);
        }
        $companyId = app('currentCompanyId');
        // pos_tax_inclusive stays SYNCED (1 for both inclusive variants) so every
        // existing branch on the boolean keeps working.
        $update = ['pos_tax_inclusive' => $mode !== 'exclusive'];
        if ($modeColumnExists) {
            $update['pos_tax_pricing_mode'] = $mode;
        }
        Company::where('id', $companyId)->update($update);
        return response()->json(['success' => true, 'mode' => $mode, 'inclusive' => $mode !== 'exclusive']);
    }

    public function updateRestockToggle(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        $enabled = $request->boolean('enabled');
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_restock_on_void' => $enabled]);
        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    public function updateInventoryToggle(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        $enabled = $request->boolean('enabled');
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if ($company) {
            // Keep the Features-wizard "Inventory Tracking" flag in lockstep
            // with the master module switch so both surfaces always agree.
            $flags = is_array($company->feature_flags) ? $company->feature_flags : [];
            $flags['inventory'] = $enabled;
            // Canonicalize so dependent children (e.g. recipes) cascade off in
            // STORED flags too — keeps the Features wizard display truthful.
            $flags = \App\Services\PosFeatureService::normalize($flags);
            $company->update(['inventory_enabled' => $enabled, 'feature_flags' => $flags]);
        }
        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    /**
     * Receipt Display Options — moved out of Business Profile into its own
     * Customize POS sub-page.
     */
    public function receiptSettings(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can change receipt settings.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }

        if ($request->isMethod('post')) {
            $request->validate([
                'rp_footer_text' => 'nullable|string|max:150',
                'lp_footer_text' => 'nullable|string|max:150',
                'rp_printer_size' => 'nullable|in:80mm,58mm',
                'rp_logo_style' => 'nullable|in:side,center',
                'rp_pdf_paper' => 'nullable|in:thermal,a4',
            ]);
            $prefs = $company->invoice_display_prefs ?? [];
            // PRA (fiscal) receipt set — legacy 'pos' key, backward compatible.
            $prefs['pos'] = [
                'show_address' => $request->has('rp_show_address'),
                'show_ntn' => $request->has('rp_show_ntn'),
                'show_email' => $request->has('rp_show_email'),
                'show_mobile' => $request->has('rp_show_mobile'),
                'show_cashier' => $request->has('rp_show_cashier'),
                'show_footer' => $request->has('rp_show_footer'),
                'show_business_name' => $request->has('rp_show_business_name'),
                'show_developed_by' => $request->has('rp_show_developed_by'),
                'footer_text' => trim((string) $request->input('rp_footer_text', '')) ?: null,
            ];
            // Local (L-series) receipt set — owner request Jul 2026: PRA and Local
            // bills each get their OWN full display set (incl. its own show_tax).
            $prefs['pos_local'] = [
                'show_address' => $request->has('lp_show_address'),
                'show_ntn' => $request->has('lp_show_ntn'),
                'show_email' => $request->has('lp_show_email'),
                'show_mobile' => $request->has('lp_show_mobile'),
                'show_cashier' => $request->has('lp_show_cashier'),
                'show_footer' => $request->has('lp_show_footer'),
                'show_business_name' => $request->has('lp_show_business_name'),
                'show_developed_by' => $request->has('lp_show_developed_by'),
                'show_tax' => $request->has('lp_show_tax'),
                'footer_text' => trim((string) $request->input('lp_footer_text', '')) ?: null,
            ];
            // Print Style (Pizza Master Jul 2026): GLOBAL like paper size — bold
            // whole-receipt font + logo size/placement. Applies to both bill types.
            $prefs['pos_style'] = [
                'bold' => $request->has('rp_style_bold'),
                'logo' => $request->input('rp_logo_style', 'side') === 'center' ? 'center' : 'side',
                // PDF Download Paper (customer video Jul 2026): 'thermal' = exact
                // roll-width PDF page (default); 'a4' = real A4 page, receipt strip
                // top-left — fixes right-shifted/clipped prints on office printers.
                'pdf_paper' => $request->input('rp_pdf_paper') === 'a4' ? 'a4' : 'thermal',
                // Logo on finals only: when ON, logo prints only on final/PRA bills —
                // suppressed on local/provisional (invoice_mode='local') bills.
                'logo_finals_only' => $request->has('rp_logo_finals_only'),
            ];
            $company->update([
                'invoice_display_prefs' => $prefs,
                // Owner decision (Jul 2026): tax display toggle lives HERE (receipt
                // customization), not on the Features page. OFF = customer copy
                // shows grand TOTAL only; tax is always submitted to PRA in full.
                // Since the PRA/Local split this column is the PRA-receipt tax toggle.
                'pos_receipt_show_tax' => $request->has('rp_show_tax'),
                // Paper size (owner request Jul 2026): same column PRA Settings writes —
                // last save from either page wins. Missing/invalid input keeps 80mm default.
                'receipt_printer_size' => $request->input('rp_printer_size', $company->receipt_printer_size ?? '80mm'),
            ]);
            return redirect()->route('pos.receipt-settings')->with('success', __('pos.receipt_display_settings_saved'));
        }

        return view('pos.receipt-settings', compact('company'));
    }

    /**
     * Printer Settings — silent printing via the Desktop Sync Agent (admin-only).
     * Admin picks which installed printer receives customer bills and which
     * receives kitchen tickets (KOT). Default OFF; popup printing stays the
     * fallback whenever the agent is offline or silent print is disabled.
     */
    public function printerSettings(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can change printer settings.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'silent_print_enabled' => 'nullable|boolean',
                'receipt_printer' => 'nullable|string|max:255',
                'kot_printer' => 'nullable|string|max:255',
                'counter_kot_printer' => 'nullable|string|max:255',
                'counter_kot_enabled' => 'nullable|boolean',
            ]);

            $settings = $company->printerSettings();
            $known = collect($settings['available_printers'])->pluck('name')->all();

            // Only accept printers the agent actually reported (or blank = unset).
            $receipt = trim((string) ($validated['receipt_printer'] ?? ''));
            $kot = trim((string) ($validated['kot_printer'] ?? ''));
            $settings['receipt_printer'] = ($receipt !== '' && in_array($receipt, $known, true)) ? $receipt : null;
            $settings['kot_printer'] = ($kot !== '' && in_array($kot, $known, true)) ? $kot : null;
            // Counter KOT Copy (dine-in only): printer + its own ON/OFF tick.
            $counterKot = trim((string) ($validated['counter_kot_printer'] ?? ''));
            $settings['counter_kot_printer'] = ($counterKot !== '' && in_array($counterKot, $known, true)) ? $counterKot : null;
            $settings['counter_kot_enabled'] = $request->boolean('counter_kot_enabled') && $settings['counter_kot_printer'];
            $settings['silent_print_enabled'] = $request->boolean('silent_print_enabled')
                && ($settings['receipt_printer'] || $settings['kot_printer']);
            // Manual save = deliberate choice — the sale-screen one-click prompt
            // must never nag this shop again (even if they chose to stay OFF).
            $settings['prompt_dismissed_at'] = $settings['prompt_dismissed_at'] ?? now()->toIso8601String();

            $company->update(['pos_printer_settings' => $settings]);

            return redirect()->route('pos.printer-settings')->with('success', __('pos.printer_settings_saved'));
        }

        $settings = $company->printerSettings();
        $agentOnline = $company->agentOnline();
        $recentFailed = \App\Models\PosPrintJob::where('company_id', $companyId)
            ->where('status', 'failed')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('pos.printer-settings', compact('company', 'settings', 'agentOnline', 'recentFailed'));
    }

    /**
     * One-click silent-print prompt (sale-screen banner, admins/managers only).
     * POST {action: enable|dismiss}. Enable recomputes the smart printer pick
     * SERVER-side (never trusts the client), validates it against the agent's
     * reported printers, and turns on silent BILL printing only — KOT routing
     * stays a manual choice on Printer Settings (a wrong KOT device would send
     * kitchen tickets to the counter). Dismiss stamps prompt_dismissed_at so
     * the shop is never nagged again.
     */
    public function apiPrinterPrompt(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) { abort(403); }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }

        $validated = $request->validate(['action' => 'required|in:enable,dismiss']);
        $settings = $company->printerSettings();

        if ($validated['action'] === 'dismiss') {
            $settings['prompt_dismissed_at'] = now()->toIso8601String();
            $company->update(['pos_printer_settings' => $settings]);
            return response()->json(['success' => true]);
        }

        $pick = self::smartPrinterPick($settings['available_printers']);
        if (!$pick) {
            return response()->json(['success' => false, 'reason' => 'no_confident_pick'], 409);
        }
        $settings['receipt_printer'] = $pick;
        $settings['silent_print_enabled'] = true;
        $settings['prompt_dismissed_at'] = now()->toIso8601String();
        $company->update(['pos_printer_settings' => $settings]);

        return response()->json(['success' => true, 'printer' => $pick]);
    }

    /**
     * Smart receipt-printer pick from the agent's reported printers:
     * (a) first name/displayName that LOOKS thermal (pos/thermal/receipt/80/58/
     * rp-/xp-/tm- patterns) and is not a virtual device; (b) else the Windows
     * default printer unless it is clearly virtual (PDF/XPS/OneNote/Fax);
     * (c) else null = no confident pick — the banner then links to Printer
     * Settings instead of one-click enabling. NEVER auto-pick blind: a wrong
     * device means receipts silently go nowhere (worse than popup printing).
     */
    public static function smartPrinterPick(array $printers): ?string
    {
        $thermal = '/pos|thermal|receipt|\b80\b|\b58\b|rp-|xp-|tm-/i';
        $virtual = '/pdf|xps|onenote|fax|journal/i';
        foreach ($printers as $p) {
            $hay = ($p['name'] ?? '') . ' ' . ($p['displayName'] ?? '');
            if (preg_match($thermal, $hay) && !preg_match($virtual, $hay)) {
                return $p['name'];
            }
        }
        foreach ($printers as $p) {
            $hay = ($p['name'] ?? '') . ' ' . ($p['displayName'] ?? '');
            if (!empty($p['isDefault']) && !preg_match($virtual, $hay)) {
                return $p['name'];
            }
        }
        return null;
    }

    /**
     * Print-failure telemetry beacon (Task #63, 31 Jul 2026 — Pizza Master's
     * vanished delivery bill: no print job row ever reached the server and the
     * client-side cause was unprovable). The sale screen now reports print
     * failures/skips here (navigator.sendBeacon — survives page unload) so the
     * ROOT CAUSE is in storage/logs next time. Log-only by design: never
     * blocks, never throws, accepts a minimal whitelisted payload.
     */
    public function apiPrintTelemetry(Request $request)
    {
        try {
            $user = auth('pos')->user();
            $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : ($user->company_id ?? null);
            $data = $request->json()->all();
            if (!is_array($data)) { $data = []; }
            \Illuminate\Support\Facades\Log::warning('POS_PRINT_TELEMETRY', [
                'company_id' => $companyId,
                'user_id' => $user?->id,
                'stage' => substr((string) ($data['stage'] ?? ''), 0, 60),
                'type' => substr((string) ($data['type'] ?? ''), 0, 20),
                'transaction_id' => is_numeric($data['transaction_id'] ?? null) ? (int) $data['transaction_id'] : null,
                'order_id' => is_numeric($data['order_id'] ?? null) ? (int) $data['order_id'] : null,
                'error' => substr((string) ($data['error'] ?? ''), 0, 300),
                'http_status' => is_numeric($data['http_status'] ?? null) ? (int) $data['http_status'] : null,
                'flags' => substr((string) ($data['flags'] ?? ''), 0, 200),
                'online' => $data['online'] ?? null,
                'at_client' => substr((string) ($data['at'] ?? ''), 0, 40),
                'ua' => substr((string) $request->userAgent(), 0, 120),
            ]);
        } catch (\Throwable $e) { /* telemetry must never 500 */ }
        return response()->json(['ok' => true]);
    }

    /**
     * Session-authed enqueue of a silent print job (bill receipt or KOT).
     * Returns 409 when silent printing cannot happen right now (disabled,
     * printer not chosen, or agent offline) — the sale screen falls back to
     * the normal popup/iframe print path on ANY non-2xx.
     */
    public function apiCreatePrintJob(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user) { abort(403); }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }

        $validated = $request->validate([
            'type' => 'required|in:bill,kot,proof',
            'transaction_id' => 'required_if:type,bill|nullable|integer',
            // kot: restaurant_order_id OR transaction_id (order-less delivery bills)
            'restaurant_order_id' => 'required_if:type,proof|nullable|integer',
            'delta' => 'nullable|boolean',
            // Counter/Station routing: a station-pinned KDS device enqueues ONLY
            // its own counter's ticket (0 = main Kitchen bucket).
            'station_id' => 'nullable|integer',
        ]);

        $settings = $company->printerSettings();
        if (!$settings['silent_print_enabled']) {
            return response()->json(['success' => false, 'reason' => 'disabled'], 409);
        }
        if (!$company->agentOnline()) {
            return response()->json(['success' => false, 'reason' => 'agent_offline'], 409);
        }

        // ── BILL: single job, receipt printer (unchanged behavior) ─────────
        if ($validated['type'] === 'bill') {
            if (!$settings['receipt_printer']) {
                return response()->json(['success' => false, 'reason' => 'no_printer'], 409);
            }
            $exists = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('id', (int) $validated['transaction_id'])
                ->exists();
            if (!$exists) {
                return response()->json(['success' => false, 'reason' => 'not_found'], 404);
            }
            // Impatient double-press guard (Malik Chicken Broast, 23 Jul 2026):
            // laser/agent latency means the paper can take ~20s to come out, so
            // cashiers press Print again and get a duplicate physical copy.
            // If this SAME bill is already queued/printing (job < 2 min old —
            // matches the agent's stale-requeue window), don't enqueue a second
            // copy; report success with a deduped flag so the UI can explain.
            // Once the job is done, a fresh press = legitimate reprint (allowed).
            $inFlight = \App\Models\PosPrintJob::where('company_id', $companyId)
                ->where('type', 'bill')
                ->where('transaction_id', (int) $validated['transaction_id'])
                ->whereIn('status', ['pending', 'printing'])
                ->where('created_at', '>=', now()->subMinutes(2))
                ->orderByDesc('id')
                ->first();
            if ($inFlight) {
                return response()->json(['success' => true, 'job_id' => $inFlight->id, 'deduped' => true]);
            }
            $job = \App\Models\PosPrintJob::create([
                'company_id' => $companyId,
                'type' => 'bill',
                'target_printer' => $settings['receipt_printer'],
                'transaction_id' => (int) $validated['transaction_id'],
                'status' => 'pending',
                'created_by' => $user->id,
            ]);
            return response()->json(['success' => true, 'job_id' => $job->id]);
        }

        // ── PROOF BILL (ZFC 28 Jul 2026): pre-bill on the RECEIPT printer —
        // silent path so the desktop app never pops the Windows print dialog. ──
        if ($validated['type'] === 'proof') {
            if (!$settings['receipt_printer']) {
                return response()->json(['success' => false, 'reason' => 'no_printer'], 409);
            }
            $exists = \App\Models\RestaurantOrder::where('company_id', $companyId)
                ->where('id', (int) $validated['restaurant_order_id'])
                ->exists();
            if (!$exists) {
                return response()->json(['success' => false, 'reason' => 'not_found'], 404);
            }
            // In-flight dedupe (30 Jul 2026): the client now RETRIES on network
            // blips — a first request that succeeded server-side must not print
            // a second physical copy. Mirrors the bill double-press guard.
            $inFlight = \App\Models\PosPrintJob::where('company_id', $companyId)
                ->where('type', 'proof')
                ->where('restaurant_order_id', (int) $validated['restaurant_order_id'])
                ->whereIn('status', ['pending', 'printing'])
                ->where('created_at', '>=', now()->subMinutes(2))
                ->orderByDesc('id')->first();
            if ($inFlight) {
                return response()->json(['success' => true, 'job_id' => $inFlight->id, 'deduped' => true]);
            }
            $job = \App\Models\PosPrintJob::create([
                'company_id' => $companyId,
                'type' => 'proof',
                'target_printer' => $settings['receipt_printer'],
                'restaurant_order_id' => (int) $validated['restaurant_order_id'],
                'status' => 'pending',
                'created_by' => $user->id,
            ]);
            return response()->json(['success' => true, 'job_id' => $job->id]);
        }

        // KOT needs ONE of the two ids — clean 422 instead of an undefined-key 500.
        if (!$request->filled('restaurant_order_id') && !$request->filled('transaction_id')) {
            return response()->json(['success' => false, 'reason' => 'missing_id'], 422);
        }

        // ── KOT from a TRANSACTION (order-less delivery bills, ZFC 28 Jul 2026) ──
        if (!$request->filled('restaurant_order_id') && $request->filled('transaction_id')) {
            if (!$settings['kot_printer']) {
                return response()->json(['success' => false, 'reason' => 'no_printer'], 409);
            }
            $exists = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('id', (int) $validated['transaction_id'])
                ->exists();
            if (!$exists) {
                return response()->json(['success' => false, 'reason' => 'not_found'], 404);
            }
            // In-flight dedupe — see proof branch (client-side retry, 30 Jul 2026).
            $inFlight = \App\Models\PosPrintJob::where('company_id', $companyId)
                ->where('type', 'kot')
                ->where('transaction_id', (int) $validated['transaction_id'])
                ->whereIn('status', ['pending', 'printing'])
                ->where('created_at', '>=', now()->subMinutes(2))
                ->orderByDesc('id')->first();
            if ($inFlight) {
                return response()->json(['success' => true, 'job_id' => $inFlight->id, 'deduped' => true]);
            }
            $job = \App\Models\PosPrintJob::create([
                'company_id' => $companyId,
                'type' => 'kot',
                'target_printer' => $settings['kot_printer'],
                'transaction_id' => (int) $validated['transaction_id'],
                'status' => 'pending',
                'created_by' => $user->id,
            ]);
            return response()->json(['success' => true, 'job_id' => $job->id]);
        }

        // ── KOT ─────────────────────────────────────────────────────────────
        $order = \App\Models\RestaurantOrder::where('company_id', $companyId)
            ->with('items')
            ->find((int) $validated['restaurant_order_id']);
        if (!$order) {
            return response()->json(['success' => false, 'reason' => 'not_found'], 404);
        }

        $delta = $request->boolean('delta');
        $deltaQ = $delta ? '&delta=1' : '';
        $stations = \App\Models\PosStation::activeFor($companyId);
        // Counter KOT Copy (owner request 30 Jul 2026): DINE-IN orders only —
        // one FULL (non-station-split) copy of the KOT on the counter printer,
        // in ADDITION to the normal kitchen job(s). Best-effort: never blocks
        // or fails the main kitchen print.
        $counterCopy = function () use ($settings, $order, $companyId, $user, $delta) {
            try {
                if (!($settings['counter_kot_enabled'] ?? false)) return;
                $printer = $settings['counter_kot_printer'] ?? null;
                if (!$printer || ($order->order_type ?? null) !== 'dine_in') return;
                // In-flight dedupe — client retry must not double the counter copy.
                $dupe = \App\Models\PosPrintJob::where('company_id', $companyId)
                    ->where('type', 'kot')
                    ->where('restaurant_order_id', $order->id)
                    ->where('target_printer', $printer)
                    ->where(fn($q) => $delta ? $q->where('render_query', 'delta=1') : $q->whereNull('render_query'))
                    ->whereIn('status', ['pending', 'printing'])
                    ->where('created_at', '>=', now()->subMinutes(2))
                    ->exists();
                if ($dupe) return;
                \App\Models\PosPrintJob::create([
                    'company_id' => $companyId,
                    'type' => 'kot',
                    'target_printer' => $printer,
                    'restaurant_order_id' => $order->id,
                    'render_query' => $delta ? 'delta=1' : null,
                    'status' => 'pending',
                    'created_by' => $user->id,
                ]);
            } catch (\Throwable $e) { /* copy is optional — kitchen print already queued */ }
        };
        $makeJob = function (?string $printer, ?string $renderQuery) use ($companyId, $order, $user) {
            // In-flight dedupe (client retry + KDS/cashier race, 30 Jul 2026):
            // an identical queued/printing job < 2 min old = same physical ticket
            // already on its way. Safe for deltas too — a PENDING delta job
            // renders unprinted rows at PRINT time, so one job covers both fires.
            $inFlight = \App\Models\PosPrintJob::where('company_id', $companyId)
                ->where('type', 'kot')
                ->where('restaurant_order_id', $order->id)
                ->where('target_printer', $printer)
                ->where(fn($q) => $renderQuery === null ? $q->whereNull('render_query') : $q->where('render_query', $renderQuery))
                ->whereIn('status', ['pending', 'printing'])
                ->where('created_at', '>=', now()->subMinutes(2))
                ->orderByDesc('id')->first();
            if ($inFlight) return $inFlight;
            return \App\Models\PosPrintJob::create([
                'company_id' => $companyId,
                'type' => 'kot',
                'target_printer' => $printer,
                'restaurant_order_id' => $order->id,
                'render_query' => $renderQuery,
                'status' => 'pending',
                'created_by' => $user->id,
            ]);
        };

        // Zero stations => single full/delta KOT on the company KOT printer
        // (byte-identical to pre-station behavior).
        if ($stations->isEmpty()) {
            if (!$settings['kot_printer']) {
                return response()->json(['success' => false, 'reason' => 'no_printer'], 409);
            }
            $job = $makeJob($settings['kot_printer'], $delta ? 'delta=1' : null);
            $counterCopy();
            return response()->json(['success' => true, 'job_id' => $job->id]);
        }

        // Station-pinned device (KDS counter screen): ONE job for that bucket.
        if ($request->filled('station_id')) {
            $sid = (int) $validated['station_id'];
            $station = $sid === \App\Models\PosStation::DEFAULT_ID ? null : $stations->firstWhere('id', $sid);
            if ($sid !== \App\Models\PosStation::DEFAULT_ID && !$station) {
                return response()->json(['success' => false, 'reason' => 'not_found'], 404);
            }
            $printer = ($station->printer_name ?? null) ?: $settings['kot_printer'];
            if (!$printer) {
                return response()->json(['success' => false, 'reason' => 'no_printer'], 409);
            }
            $job = $makeJob($printer, 'station=' . $sid . $deltaQ);
            return response()->json(['success' => true, 'job_id' => $job->id]);
        }

        // Cashier path with stations configured: SPLIT — one job per station
        // that actually has items, each to its own printer (fallback: company
        // KOT printer). Agent renders each with render_query station=ID; empty
        // buckets never become jobs. If ANY bucket lacks a printer, 409 so the
        // caller falls back to the classic full-ticket popup (nothing lost).
        $baseItems = $delta ? $order->items->whereNull('kot_printed_at')->values() : $order->items;
        $itemMap = \App\Models\PosStation::mapItems($companyId, $stations, $baseItems);
        $sids = collect($itemMap)->values()->unique()->sort()->values();

        if ($sids->isEmpty()) {
            // Nothing to print (e.g. delta with no unprinted rows) — succeed with no jobs.
            return response()->json(['success' => true, 'job_ids' => []]);
        }

        $plan = [];
        foreach ($sids as $sid) {
            $station = $sid === \App\Models\PosStation::DEFAULT_ID ? null : $stations->firstWhere('id', $sid);
            $printer = ($station->printer_name ?? null) ?: $settings['kot_printer'];
            if (!$printer) {
                return response()->json(['success' => false, 'reason' => 'no_printer'], 409);
            }
            $plan[] = [$printer, 'station=' . $sid . $deltaQ];
        }
        $jobIds = [];
        foreach ($plan as [$printer, $rq]) {
            $jobIds[] = $makeJob($printer, $rq)->id;
        }
        $counterCopy();
        return response()->json(['success' => true, 'job_ids' => $jobIds]);
    }

    /**
     * Customize POS — single consolidated settings hub (admin-only).
     * Surfaces every POS customization feature from one place; complex
     * sub-features link out to their existing pages.
     */
    public function customize(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can customize POS.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }
        return view('pos.customize', compact('company'));
    }

    public function dashboard(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // First-time POS admins are guided through the setup wizard before anything else.
        if ($this->needsPosSetup($company)) {
            return redirect()->route('pos.features', ['welcome' => 1]);
        }

        // Business day (owner rule 26 Jul 2026): dashboard "today" = the OPEN
        // trading day — after midnight (before 6 AM) with yesterday un-closed,
        // "aaj" is still yesterday's business day. All day-bucket KPIs read
        // business_date; created_at stays for timestamps/PRA truth.
        $bizToday = \App\Services\PosBusinessDay::current($companyId);

        // Local (non-PRA) bills are excluded from ALL dashboard KPIs — they are
        // visible only in the isolated Local Bills Portal (pos_role='local_viewer').
        $excludeLocal = function ($q) {
            $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
        };
        $excludeLocalRaw = function ($q) {
            $q->where('t.invoice_mode', 'pra')->orWhereNull('t.invoice_mode');
        };

        $todayStats = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('business_date', $bizToday)
            ->where($excludeLocal)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue, COALESCE(AVG(total_amount),0) as avg_ticket')
            ->first();

        // Task 109 (ZFC, 2 Aug 2026): Pending Bills — provisional bills of the
        // current BUSINESS day that are still not FINAL. Triple-filter per
        // pos-provisional rules (completed + invoice_mode='local' +
        // pra_status='local'); hide_archived global scope excludes archived.
        $pendingProvisional = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('invoice_mode', 'local')
            ->where('pra_status', 'local')
            ->where('business_date', $bizToday)
            ->count();

        $monthStats = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('business_date', '>=', now()->startOfMonth()->toDateString())
            ->where($excludeLocal)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue')
            ->first();

        // ── PROFIT + BI ENGINE (v18) ─────────────────────────────────────────
        // Period filter: ?period=today | week | month  (default: today)
        $period = in_array($request->query('period'), ['today', 'week', 'month'], true)
            ? $request->query('period') : 'today';
        $periodStart = match ($period) {
            'week'  => now()->startOfWeek()->toDateString(),
            'month' => now()->startOfMonth()->toDateString(),
            default => $bizToday,
        };

        // Cost / profit aggregation: JOIN items → products to read cost_price.
        // unit_price kept from item (snapshot at time of sale).
        // Profit = (item.subtotal - cost_price * quantity) summed across all completed orders in period.
        $profitRow = \DB::table('pos_transactions as t')
            ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
            ->leftJoin('pos_products as p', function ($j) {
                $j->on('p.id', '=', 'i.item_id')->where('i.item_type', '=', 'product');
            })
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->where('t.business_date', '>=', $periodStart)
            ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
            ->where($excludeLocalRaw)
            ->selectRaw('
                COALESCE(SUM(i.subtotal), 0) as gross_revenue,
                COALESCE(SUM(COALESCE(p.cost_price, 0) * i.quantity), 0) as total_cost
            ')->first();

        $periodOrders = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('business_date', '>=', $periodStart)
            ->where($excludeLocal)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue')
            ->first();

        $totalCost     = (float) ($profitRow->total_cost ?? 0);
        $totalRevenue  = (float) ($periodOrders->revenue ?? 0);
        $totalProfit   = max(0, $totalRevenue - $totalCost); // floor at 0 for display
        $marginPct     = $totalRevenue > 0 ? round(($totalRevenue - $totalCost) / $totalRevenue * 100, 1) : 0;

        $profitStats = [
            'period'   => $period,
            'orders'   => (int) ($periodOrders->count ?? 0),
            'revenue'  => $totalRevenue,
            'cost'     => $totalCost,
            'profit'   => $totalProfit,
            'margin'   => $marginPct,
        ];

        // Top products by quantity sold (period)
        $topSold = \DB::table('pos_transactions as t')
            ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->where('t.business_date', '>=', $periodStart)
            ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
            ->where($excludeLocalRaw)
            ->where('i.item_type', 'product')
            ->selectRaw('i.item_id, MAX(i.item_name) as name, SUM(i.quantity) as qty, SUM(i.subtotal) as revenue')
            ->groupBy('i.item_id')
            ->orderByDesc('qty')
            ->limit(5)
            ->get();

        // Top products by profit (period) — needs cost_price
        $topProfit = \DB::table('pos_transactions as t')
            ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
            ->join('pos_products as p', 'p.id', '=', 'i.item_id')
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->where('t.business_date', '>=', $periodStart)
            ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
            ->where($excludeLocalRaw)
            ->where('i.item_type', 'product')
            ->selectRaw('
                i.item_id, MAX(i.item_name) as name,
                SUM(i.subtotal) as revenue,
                SUM(COALESCE(p.cost_price,0) * i.quantity) as cost,
                SUM(i.subtotal - COALESCE(p.cost_price,0) * i.quantity) as profit
            ')
            ->groupBy('i.item_id')
            ->orderByDesc('profit')
            ->limit(5)
            ->get();

        // Low margin alert: products with cost_price > 0 AND (price - cost)/price < 15%
        $lowMargin = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('cost_price', '>', 0)
            ->whereRaw('price > 0')
            ->whereRaw('((price - cost_price) / price) < 0.15')
            ->orderByRaw('((price - cost_price) / NULLIF(price,0)) asc')
            ->limit(5)
            ->get(['id', 'name', 'price', 'cost_price']);

        // Coverage: how many active products have cost_price set (helps user understand accuracy)
        $costCoverage = [
            'with_cost' => PosProduct::where('company_id', $companyId)->where('is_active', true)->where('cost_price', '>', 0)->count(),
            'total'     => PosProduct::where('company_id', $companyId)->where('is_active', true)->count(),
        ];
        // ─────────────────────────────────────────────────────────────────────

        $recentTransactions = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $paymentBreakdown = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('business_date', $bizToday)
            ->where($excludeLocal)
            ->selectRaw("payment_method, COUNT(*) as count, COALESCE(SUM(total_amount),0) as total")
            ->groupBy('payment_method')
            ->get();

        // Per-cashier toggle (owner rule Jul 2026): dashboard pill shows THIS user's
        // effective reporting state, not the company-wide flag.
        $praStatus = (bool) auth('pos')->user()?->praReportingEnabled($company);

        $drafts = PosTransaction::where('company_id', $companyId)
            ->where('status', 'draft')
            ->with('items')
            ->orderBy('updated_at', 'desc')
            ->take(20)
            ->get();

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';

        $allowedStyles = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify', 'saaf'];
        $dashboardStyle = in_array($company->pos_dashboard_style, $allowedStyles) ? $company->pos_dashboard_style : 'default';
        $isRestaurant = false;
        $isAdmin = !$isCashier;

        // Saaf style extras (lazy — only queried when the clean dashboard is active):
        // yesterday's revenue for the vs-kal delta + today's PRA-synced bill count.
        $yesterdayRevenue = null;
        $praSyncedToday = null;
        if ($dashboardStyle === 'saaf') {
            $yesterdayRevenue = (float) PosTransaction::where('company_id', $companyId)
                ->where('status', 'completed')
                ->where('business_date', \Carbon\Carbon::parse($bizToday)->subDay()->toDateString())
                ->where($excludeLocal)
                ->sum('total_amount');
            $praSyncedToday = PosTransaction::where('company_id', $companyId)
                ->where('status', 'completed')
                ->where('business_date', $bizToday)
                ->where($excludeLocal)
                ->where('pra_status', 'submitted')
                ->count();
        }

        // Same pattern as DI dashboard: unread company notifications, 30-day auto-expiry.
        $notifications = \App\Models\Notification::where('company_id', $companyId)
            ->where('read', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        // Opening Cash Balance (Jul 2026): today's drawer opening — card on the
        // dashboard prompts entry at day start; locked once today is closed.
        // Task 56: keys on the BUSINESS day (per-company cutoff) so at 2 AM a
        // late-night shop still sees/locks yesterday's drawer opening.
        $todayDate = $bizToday;
        $dayOpening = \App\Models\PosDayOpening::forDate($companyId, $todayDate);
        $todayClosed = PosDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $todayDate)
            ->exists();

        return view('pos.dashboard', compact(
            'company', 'todayStats', 'monthStats', 'recentTransactions', 'paymentBreakdown', 'praStatus', 'drafts', 'isCashier',
            'dashboardStyle', 'isRestaurant', 'isAdmin', 'notifications',
            'profitStats', 'topSold', 'topProfit', 'lowMargin', 'costCoverage',
            'dayOpening', 'todayClosed', 'yesterdayRevenue', 'praSyncedToday',
            'pendingProvisional'
        ));
    }

    /**
     * Opening Cash Balance — save/update the drawer's opening cash for a date
     * (default today). Cashiers ARE allowed (it's their day-start job); the
     * entry locks once that date's day-close report exists. Upsert: one row
     * per company per business date.
     */
    public function saveDayOpening(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();

        $request->validate([
            'opening_cash' => 'required|numeric|min:0|max:99999999',
            'notes' => 'nullable|string|max:500',
        ]);
        // Opening cash is a TODAY-only entry — the UI never sends a date, and
        // accepting one would let a raw POST seed arbitrary future/past days.
        // Task 56: "today" = the company's current BUSINESS day (per-company
        // cutoff), matching the dashboard card that posts here.
        $date = \App\Services\PosBusinessDay::current($companyId);

        if (!\Schema::hasTable('pos_day_openings')) {
            return back()->with('error', __('pos.opening_cash_feature_setup'));
        }

        // Once the day is closed the Z-report is immutable — opening locks too.
        $closed = PosDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)
            ->exists();
        if ($closed) {
            return back()->with('error', __('pos.day_closed_opening_cash_locked'));
        }

        \App\Models\PosDayOpening::updateOrCreate(
            ['company_id' => $companyId, 'business_date' => $date],
            [
                'opening_cash' => round((float) $request->input('opening_cash'), 2),
                'entered_by' => $user?->id,
                'notes' => $request->input('notes'),
            ]
        );

        return back()->with('success', __('pos.opening_cash_saved', ['amount' => number_format((float) $request->input('opening_cash'), 2)]));
    }

    /**
     * Dismiss (mark read) a single in-app notification — mirrors
     * DashboardController::dismissNotification. NEVER deletes rows:
     * SendTrialReminders dedupes on row existence.
     */
    public function dismissNotification($id)
    {
        $companyId = app('currentCompanyId');

        \App\Models\Notification::where('company_id', $companyId)
            ->where('id', $id)
            ->update(['read' => true]);

        return back();
    }

    public function dismissAllNotifications()
    {
        $companyId = app('currentCompanyId');

        \App\Models\Notification::where('company_id', $companyId)
            ->where('read', false)
            ->update(['read' => true]);

        return back();
    }

    public function createInvoice(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        \Log::info('POS MODE ACTIVE', [
            'company_id' => $company?->id,
            'mode' => 'UNIVERSAL',
        ]);

        return $this->universalCreateInvoice($request);

        // Legacy paths preserved (unreachable, kept for rollback reference)
        if ($company && $company->restaurant_mode) {
            return app(RestaurantPosController::class)->pos($request);
        }

        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $posCustomers = PosCustomer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $taxRules = PosTaxRule::effectiveRules($company);
        $terminals = PosTerminal::where('company_id', $companyId)->where('is_active', true)->get();

        $draftInvoice = null;
        if ($request->has('draft_id')) {
            $draftInvoice = PosTransaction::where('company_id', $companyId)
                ->where('id', $request->draft_id)
                ->where('status', 'draft')
                ->with('items')
                ->first();

            if ($draftInvoice) {
                $currentTerminalId = $request->input('terminal_id');
                if ($draftInvoice->isLocked() && $currentTerminalId && $draftInvoice->locked_by_terminal_id != $currentTerminalId) {
                    $lockedTerminal = PosTerminal::find($draftInvoice->locked_by_terminal_id);
                    $terminalName = $lockedTerminal ? $lockedTerminal->terminal_name : 'Unknown';
                    return redirect()->route('pos.invoice.create')
                        ->with('error', __('pos.invoice_being_edited_terminal', ['terminal' => $terminalName]));
                }

                if ($currentTerminalId) {
                    $draftInvoice->acquireLock((int) $currentTerminalId);
                }
            }
        }

        $pendingDrafts = PosTransaction::where('company_id', $companyId)
            ->where('status', 'draft')
            ->where('created_by', auth('pos')->id())
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get();

        return view('pos.create-invoice', compact('company', 'products', 'services', 'taxRules', 'terminals', 'draftInvoice', 'pendingDrafts', 'posCustomers'));
    }

    public function universalCreateInvoice(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // First-time POS admins are guided through the setup wizard before billing.
        if ($this->needsPosSetup($company)) {
            return redirect()->route('pos.features', ['welcome' => 1]);
        }

        $features = PosFeatureService::forCompany($company);

        // Load ALL active products (including show_on_sale=false). "Hidden from sale screen"
        // products are kept OUT of the browsable grid on the client, but MUST stay loaded so
        // the cashier can still SEARCH them by name and add them to the cart at any time.
        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $categories = $products->where('show_on_sale', true)->pluck('category')->filter()->unique()->sort()->values();
        $productIds = $products->pluck('id')->toArray();

        $recipeLookup = [];
        $stockStatus = [];
        $ingredientCosts = [];
        $lowStockAlerts = collect();
        // Inventory master switch — when company has inventory_enabled = false, suppress
        // ALL stock indicators (dots, OUT pills, low-stock popup) so the POS stays clean.
        // Recipes/ingredient costing still computed for cost-of-sale reporting (admin only),
        // but no UI badges are emitted.
        $inventoryOn = (bool)($company->inventory_enabled ?? false);
        if ($features->recipes && class_exists(ProductRecipe::class)) {
            $recipeLookup = ProductRecipe::where('company_id', $companyId)
                ->whereIn('product_id', $productIds)->pluck('product_id')->unique()->toArray();
            $recipes = ProductRecipe::where('company_id', $companyId)->with('ingredient')->get()->groupBy('product_id');
            foreach ($recipes as $productId => $productRecipes) {
                $status = 'available';
                $cost = 0;
                foreach ($productRecipes as $recipe) {
                    $ing = $recipe->ingredient;
                    if (!$ing || !$ing->is_active) continue;
                    if ((float)$ing->current_stock < (float)$recipe->quantity_needed) { $status = 'out'; }
                    elseif (method_exists($ing, 'isLowStock') && $ing->isLowStock()) { $status = 'low'; }
                    $cost += (float)$recipe->quantity_needed * (float)($ing->cost_per_unit ?? 0);
                }
                $stockStatus[$productId] = $status;
                $ingredientCosts[$productId] = round($cost, 2);
            }
            if ($inventoryOn) {
                $lowStockAlerts = Ingredient::where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereColumn('current_stock', '<=', 'min_stock_level')
                    ->select('name', 'current_stock', 'min_stock_level', 'unit')->get();
            }
        }
        // Inventory OFF → wipe stock map so frontend renders no dots/OUT pills.
        if (!$inventoryOn) {
            $stockStatus = [];
        }

        $tables = collect();
        $selectedTable = null;
        if ($features->tables && class_exists(RestaurantTable::class)) {
            RestaurantTable::releaseStaleReservations($companyId);
            $tables = RestaurantTable::where('company_id', $companyId)
                ->where('is_active', true)->orderBy('sort_order')->get();
            $tableId = $request->get('table_id');
            $selectedTable = $tableId ? RestaurantTable::where('company_id', $companyId)->find($tableId) : null;
        }

        $heldOrders = collect();
        if ($features->kot && class_exists(RestaurantOrder::class)) {
            $heldOrders = RestaurantOrder::where('company_id', $companyId)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->with(['table', 'items'])->orderBy('created_at', 'desc')->get();
        }

        // Task 100 (Aug 2026): LIVE customer search for huge shops — never bake
        // thousands of customers into the page (11k rows froze boot on weak POS
        // PCs; see pos-boot-splash-perf). Over the cap we bake only the most-
        // recently-active subset as the instant/OFFLINE fallback; the server
        // search endpoint (/pos/restaurant/api/customer-search) is the source of
        // truth and finds EVERY customer. Deliberately NOT in the boot
        // fingerprint — new customers must never force a cached-screen reload.
        $custBakeCap = 500;
        $customersTruncated = PosCustomer::where('company_id', $companyId)->count() > $custBakeCap;
        $customers = $customersTruncated
            ? PosCustomer::where('company_id', $companyId)
                ->orderByDesc('updated_at')->limit($custBakeCap)
                ->get(['id', 'name', 'phone'])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()
            : PosCustomer::where('company_id', $companyId)->orderBy('name')->get(['id', 'name', 'phone']);
        $taxRate = PosTaxRule::getRateForMethod('cash', $company);
        $taxRules = PosTaxRule::effectiveRules($company);

        // Deals (Jul 2026): only deals live TODAY reach the sale screen — the
        // weekday/date-range filter is server-side so the client never sees
        // (or bills) an off-day deal. Component names resolved company-scoped.
        $dealsForJs = [];
        // Plan gate: Starter shops par deals bake hi nahi hote (buttons na dikhen).
        $activeDeals = PosFeatureService::planAllows($company, 'deals_enabled')
            ? PosDeal::where('company_id', $companyId)->where('is_active', true)->with('items')->get()
                ->filter(fn ($d) => $d->isActiveOn())
            : collect();
        if ($activeDeals->isNotEmpty()) {
            $dealProductIds = $activeDeals->flatMap(fn ($d) => $d->items->pluck('pos_product_id'))->unique();
            $dealProductNames = PosProduct::where('company_id', $companyId)
                ->whereIn('id', $dealProductIds)->pluck('name', 'id');
            foreach ($activeDeals as $deal) {
                $componentsText = $deal->items
                    ->map(fn ($di) => $di->quantity . 'x ' . ($dealProductNames[$di->pos_product_id] ?? 'Item'))
                    ->implode(' + ');
                $dealsForJs[] = [
                    'id' => $deal->id,
                    'type' => 'deal',
                    'name' => $deal->name,
                    'price' => (float) $deal->price,
                    'category' => 'Deals',
                    'components' => $componentsText,
                    'is_tax_exempt' => false,
                    'hasRecipe' => false,
                    'image' => null,
                    'stockStatus' => null,
                ];
            }
        }
        // Inventory master switch governs ALL stock behavior. When OFF:
        //   - block_out_of_stock is FORCED false (cannot block adds based on stock)
        //   - lowStockAlerts is empty (popup cannot open)
        //   - stockStatus is empty (no badges)
        $inventoryEnabled = $inventoryOn;
        $blockOutOfStock = $inventoryEnabled ? (bool)($company->block_out_of_stock ?? false) : false;

        $user = Auth::guard('pos')->user();
        $posRole = $user->pos_role ?? 'pos_cashier';
        $discountLimit = $posRole === 'pos_admin'
            ? (float)($company->manager_discount_limit ?? 50)
            : (float)($company->cashier_discount_limit ?? 50);
        $hasManagerPin = !empty($company->manager_override_pin);

        // Delivery Riders: pay-modal rider picker REMOVED (owner, 20 Jul 2026) —
        // rider assignment happens ONLY on the /pos/deliveries board after payment.

        // EDIT PROVISIONAL IN SALE SCREEN (Jul 2026): ?edit_bill={id} loads a
        // provisional bill (completed + invoice_mode='local' + pra_status='local',
        // never PRA-fiscalized) straight into the sale-screen cart. Saving goes
        // through updateTransaction (fate preserved: stays provisional, KEEPS its
        // L-serial). Non-provisional bills keep the classic edit-transaction page.
        $editBillForJs = null;
        if ($request->filled('edit_bill')) {
            $editTxn = PosTransaction::where('company_id', $companyId)
                ->where('id', (int) $request->input('edit_bill'))
                ->where('invoice_mode', 'local')
                ->where('pra_status', 'local')
                ->whereNull('pra_invoice_number')
                ->with('items')
                ->first();
            if (!$editTxn) {
                // Not a provisional (already promoted / PRA-fiscalized / wrong company)
                // → hand off to the classic edit page, which has its own guards.
                return redirect()->route('pos.transaction.edit', (int) $request->input('edit_bill'));
            }
            if ($editTxn) {
                // Best-effort customer re-link (bills snapshot name/phone only) so
                // the address book loads for delivery edits.
                $editCustomer = null;
                if ($editTxn->customer_phone) {
                    $editCustomer = PosCustomer::where('company_id', $companyId)
                        ->where('phone', $editTxn->customer_phone)
                        ->first();
                }
                $editBillForJs = [
                    'id' => (int) $editTxn->id,
                    'invoice_number' => (string) $editTxn->invoice_number,
                    'order_type' => $editTxn->order_type ?: 'takeaway',
                    'customer_id' => $editCustomer ? (int) $editCustomer->id : null,
                    'customer_name' => $editTxn->customer_name,
                    'customer_phone' => $editTxn->customer_phone,
                    'delivery_address' => $editTxn->delivery_address,
                    'discount_type' => $editTxn->discount_type ?: 'percentage',
                    'discount_value' => (float) ($editTxn->discount_value ?? 0),
                    // Normalized to updateTransaction's validation enum — a legacy /
                    // unexpected stored method must never brick the update.
                    'payment_method' => in_array($editTxn->payment_method, ['cash', 'card', 'debit_card', 'credit_card', 'qr_payment'], true)
                        ? $editTxn->payment_method
                        : 'cash',
                    'terminal_id' => $editTxn->terminal_id,
                    'notes' => $editTxn->notes,
                    'items' => $editTxn->items->map(fn ($i) => [
                        'item_id' => $i->item_id ? (int) $i->item_id : null,
                        'item_type' => $i->item_type ?: 'product',
                        'item_name' => (string) $i->item_name,
                        'quantity' => (float) $i->quantity,
                        'unit_price' => (float) $i->unit_price,
                        'special_notes' => $i->special_notes,
                        'is_tax_exempt' => (bool) $i->is_tax_exempt,
                    ])->values()->all(),
                ];
            }
        }

        // Per-USER grid visibility overrides (owner, 25 Jul 2026): map of
        // "type:id" => 0/1. Empty array until the table exists (prod drift safe
        // — mapForUser is hasTable + try/catch guarded internally).
        $userGridPrefs = \App\Models\PosUserItemPref::mapForUser(auth('pos')->id());

        // OFFLINE-FIRST BOOT (Jul 2026): fingerprint baked into the page so a
        // SW-cached copy of this screen can detect staleness via /pos/api/boot-check.
        $bootFp = $this->posBootFingerprint($company, $user);

        return response(view('pos.universal', compact(
            'company', 'features', 'products', 'services', 'categories',
            'recipeLookup', 'tables', 'selectedTable', 'heldOrders',
            'customers', 'taxRate', 'taxRules', 'stockStatus', 'blockOutOfStock',
            'posRole', 'discountLimit', 'hasManagerPin', 'ingredientCosts',
            'lowStockAlerts', 'inventoryEnabled', 'dealsForJs',
            'editBillForJs', 'userGridPrefs', 'bootFp', 'customersTruncated'
        )))
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
    }

    /**
     * OFFLINE-FIRST SALE SCREEN (Jul 2026): the service worker serves
     * /pos/invoice/create cache-first (SALE_CACHE in public/sw.js). This
     * fingerprint — baked into the rendered page AND served by bootCheck() —
     * lets a cached copy detect that it is stale (new deploy, catalog change,
     * settings change, user/company switch) and reload itself once.
     * Keys are deliberately short: u=user, c=company, s=screen file mtime,
     * cat=catalog revision, set=settings revision.
     */
    private function posBootFingerprint(Company $company, $user): array
    {
        $companyId = $company->id;
        $agg = function ($query) {
            $row = $query->selectRaw('COUNT(*) AS cnt, MAX(updated_at) AS mx')->first();
            return ($row->cnt ?? 0) . ':' . (string) ($row->mx ?? '');
        };
        $dealsAgg = $agg(PosDeal::where('company_id', $companyId));
        $catalogRev = md5(implode('|', [
            $agg(PosProduct::where('company_id', $companyId)),
            $agg(PosService::where('company_id', $companyId)),
            $dealsAgg,
            // Deals carry weekday/date windows — a day change must refresh the
            // screen, but ONLY for companies that actually have deals (ZFC,
            // 28 Jul 2026: the date-flip forced EVERY shop into a morning reload
            // — needless splash/reload churn for deal-less companies).
            str_starts_with($dealsAgg, '0:') ? '' : now()->toDateString(),
        ]));

        // Task 52 (Jul 2026): NEVER hash raw companies.updated_at here — any
        // frequent writer to the companies row (agent telemetry, counters)
        // would make every cached sale screen look stale → reload loop.
        // posConfigRev() hashes an explicit whitelist of POS-relevant fields.
        $settingsRev = md5(json_encode([
            $company->posConfigRev(),
            optional($user->updated_at)->timestamp,
            (bool) $user->praReportingEnabled($company),
            PosTaxRule::effectiveRules($company),
            $user->pos_role ?? 'pos_cashier',
            \App\Models\PosUserItemPref::mapForUser($user->id),
            // Task 117: offlineAllowed is BAKED into the sale screen — a plan
            // change (upgrade/downgrade) must refresh the offline-cached copy.
            (bool) \App\Services\PosFeatureService::planAllows($company, 'offline_enabled'),
        ]));

        $screenPath = resource_path('views/pos/universal.blade.php');
        return [
            'u' => (int) $user->id,
            'c' => (int) $companyId,
            's' => is_file($screenPath) ? (string) @filemtime($screenPath) : '0',
            'cat' => $catalogRev,
            'set' => $settingsRev,
        ];
    }

    /**
     * GET /pos/api/boot-check — tiny freshness probe for the SW-cached sale
     * screen. Never cached ('/api/' is in the SW skip list; no-store headers).
     */
    public function bootCheck()
    {
        $user = auth('pos')->user();
        $company = Company::find(app('currentCompanyId'));
        if (!$user || !$company) {
            return response()->json(['ok' => false], 401);
        }
        return response()->json(['ok' => true, 'fp' => $this->posBootFingerprint($company, $user)])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Does this company still need to run the POS setup wizard?
     * Cashiers are NEVER trapped — only POS admins configure the POS.
     * Existing companies were backfilled pos_setup_completed=true by migration,
     * so only brand-new companies auto-launch into the wizard.
     */
    private function needsPosSetup(?Company $company): bool
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return false;
        }
        if (!$company) {
            return false;
        }
        return !($company->pos_setup_completed ?? false);
    }

    public function featureSettings(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can customize POS features.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $features = PosFeatureService::forCompany($company);
        $categories = PosFeatureService::categories();
        $allFlags = PosFeatureService::ALL_FLAGS;
        // First-time setup → wizard shows welcome banner + opens on Step 1.
        $isFirstTime = $request->boolean('welcome') || !($company->pos_setup_completed ?? false);
        // Global defaults shown as placeholders in the Sales Tax Rates card.
        $globalTaxRates = [
            'cash' => PosTaxRule::getRateForMethod('cash'),
            'card' => PosTaxRule::getRateForMethod('debit_card'),
        ];
        // Restaurant module plan gating (Pro / Unlimited only).
        $restaurantAllowed = PosFeatureService::restaurantAllowed($company);
        // Trial context for the lock notice: 'trial' = access via active trial
        // (will lapse); trial-ended = flags were on but the trial expired.
        $restaurantAccessSource = PosFeatureService::restaurantAccessSource($company);
        $restaurantTrialEnded = PosFeatureService::restaurantLostToTrialExpiry($company);
        return view('pos.feature-settings', compact('company', 'features', 'categories', 'allFlags', 'isFirstTime', 'globalTaxRates', 'restaurantAllowed', 'restaurantAccessSource', 'restaurantTrialEnded'));
    }

    public function updateFeatureSettings(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can customize POS features.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }

        $allowedCategories = PosFeatureService::categories();
        $data = $request->validate([
            'business_category' => 'nullable|string|in:' . implode(',', $allowedCategories),
            'use_universal_pos' => 'nullable|boolean',
            'pos_ui_density'    => 'nullable|in:simple,standard,premium',
            'feature_flags'     => 'nullable|array',
            'pos_tax_rate_cash' => 'nullable|numeric|min:0|max:100',
            'pos_tax_rate_card' => 'nullable|numeric|min:0|max:100',
        ]);

        $flags = [];
        foreach (PosFeatureService::ALL_FLAGS as $flag) {
            $flags[$flag] = (bool) $request->input("feature_flags.$flag", false);
        }
        // Restaurant module plan gating (Jul 2026): when the plan doesn't
        // include it, IGNORE the request for restaurant flags and PRESERVE the
        // stored values — runtime masking keeps them inert, and a later plan
        // upgrade restores the shop's previous kitchen configuration.
        if (!PosFeatureService::restaurantAllowed($company)) {
            $stored = is_array($company->feature_flags) ? $company->feature_flags : [];
            foreach (PosFeatureService::RESTAURANT_FLAGS as $flag) {
                $flags[$flag] = (bool) ($stored[$flag] ?? false);
            }
        }
        // Canonicalize server-side: a child feature can't persist ON while its
        // required parent is OFF (mirrors the wizard's client-side resolveDeps).
        $flags = PosFeatureService::normalize($flags);

        $company->update([
            'business_category'    => $data['business_category'] ?? $company->business_category,
            'feature_flags'        => $flags,
            // Master inventory module switch follows the wizard's
            // "Inventory Tracking" flag so both surfaces always agree.
            'inventory_enabled'    => (bool) ($flags['inventory'] ?? false),
            'use_universal_pos'    => (bool) ($data['use_universal_pos'] ?? false),
            'pos_ui_density'       => $data['pos_ui_density'] ?? $company->pos_ui_density ?? 'standard',
            // Kitchen preferences (checkboxes — absent value = off).
            // NOTE: pos_receipt_show_tax moved to the Receipt Settings page
            // (receiptSettings) — do NOT save it here or every Features save
            // would force it off.
            'auto_print_kot'       => (bool) $request->input('auto_print_kot', false),
            'kot_reprint_enabled'  => (bool) $request->input('kot_reprint_enabled', false),
            'pos_guided_flow_enabled' => (bool) $request->input('pos_guided_flow_enabled', false),
            // Manual PRA tax-rate overrides — blank clears back to the global default.
            'pos_tax_rate_cash' => $request->filled('pos_tax_rate_cash') ? round((float) $request->input('pos_tax_rate_cash'), 2) : null,
            'pos_tax_rate_card' => $request->filled('pos_tax_rate_card') ? round((float) $request->input('pos_tax_rate_card'), 2) : null,
            // Finishing the wizard marks setup complete so it never auto-launches again.
            'pos_setup_completed'  => true,
        ]);

        // Step 3 "Start Using POS" → drop the cashier straight onto the sale screen.
        return redirect()->route('pos.invoice.create')->with('success', __('pos.pos_ready_start_billing'));
    }

    public function resetFeaturesToCategory(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can reset POS features.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }
        $allowedCategories = PosFeatureService::categories();
        $data = $request->validate([
            'business_category' => 'nullable|string|in:' . implode(',', $allowedCategories),
        ]);
        $category = $data['business_category'] ?? ($company->business_category ?: 'retail');
        $defaults = PosFeatureService::defaultsForCategory($category);
        $company->update([
            'business_category' => $category,
            'feature_flags' => $defaults,
            'inventory_enabled' => (bool) ($defaults['inventory'] ?? false),
        ]);
        return redirect()->route('pos.features')->with('success', __('pos.features_reset_defaults', ['category' => $category]));
    }

    public function storeInvoice(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // Plan gate (Aug 2026 matrix): deal LINES are server-priced, so a
        // crafted payload could bill deals even when the sale screen hides
        // them. Reject explicitly — cashier re-rings the items individually.
        if (!PosFeatureService::planAllows($company, 'deals_enabled')) {
            foreach ((array) $request->input('items', []) as $line) {
                if (($line['type'] ?? 'product') === 'deal') {
                    return response()->json(['success' => false, 'message' => __('pos.plan_locked_feature')], 422);
                }
            }
        }

        $request->validate([
            'items' => 'required|array|min:1|max:200',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:9999',
            // Sane upper bound (Rs. 10M per unit) to limit damage from any
            // tampered / malformed payload. POS line-item prices in Pakistan
            // never legitimately exceed this.
            'items.*.unit_price' => 'required|numeric|min:0|max:10000000',
            'payment_method' => 'required|in:cash,card,debit_card,credit_card,qr_payment',
            'discount_type' => 'required|in:percentage,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'cash_received' => 'nullable|numeric|min:0',
            // Item #1 (Jul 2026): delivery-address SNAPSHOT — frozen on the bill so
            // later edits to the customer's saved addresses never rewrite receipts.
            'delivery_address' => 'nullable|string|max:500',
            // OFFLINE-FIRST POS (Jul 2026): client-generated idempotency UUID —
            // present only on bills queued in IndexedDB while the device was
            // offline, replayed by the sale screen's sync engine.
            'offline_uuid' => 'nullable|string|max:64',
            // Offline Desktop Mode Phase 2 (Jul 2026): when the bill was queued
            // offline, the client sends the ORIGINAL sale moment + the cashier
            // who rang it up, so a next-morning sync doesn't stamp every bill
            // with the sync time / whoever pressed "Sync".
            'offline_queued_at' => 'nullable|date',
            'offline_queued_by' => 'nullable|integer',
            // Branch the bill was rung up on (multi-branch shops): snapshot from
            // the offline queue so a later sync books it under the right branch.
            'offline_branch_id' => 'nullable|integer',
        ]);

        // OFFLINE-FIRST replay guard: if an earlier sync attempt already stored
        // this bill (response was lost mid-flight — network dropped again, tab
        // closed, etc.), return the SAME success payload instead of creating a
        // duplicate. withoutGlobalScope so an already-archived bill (day-close
        // ran between attempts) still dedupes. Schema guard covers the brief
        // deploy-before-migrate window on PROD.
        $offlineUuid = trim((string) $request->input('offline_uuid', ''));
        $offlineUuidColumnExists = $offlineUuid !== '' && \Schema::hasColumn('pos_transactions', 'offline_uuid');
        if ($offlineUuidColumnExists) {
            $existing = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('offline_uuid', $offlineUuid)
                ->first();
            if ($existing) {
                $replayMessage = __('pos.invoice_already_synced', ['number' => $existing->invoice_number]);
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'replayed' => true,
                        'transaction_id' => $existing->id,
                        'invoice_number' => $existing->invoice_number,
                        'total_amount' => (float) $existing->total_amount,
                        'pra_invoice_number' => $existing->pra_invoice_number,
                        'pra_status' => $existing->pra_status,
                        'message' => $replayMessage,
                    ]);
                }
                return redirect()->route('pos.transaction.show', $existing->id)->with('success', $replayMessage);
            }
        }

        // Normalize 'card' alias → 'debit_card' (front-end Universal POS sends 'card';
        // PosTaxRule + PRA mapping use 'debit_card'). Without this, downstream tax
        // lookup/PRA payment-mode mapping miss the rule and fall back to defaults.
        if ($request->input('payment_method') === 'card') {
            $request->merge(['payment_method' => 'debit_card']);
        }

        // Cashier discount guardrail — mirrors RestaurantPosController::holdOrder.
        // Without this, a `pos_cashier` user could submit a 100 % percentage
        // discount through the manual-cart bypass / legacy form post and bypass
        // the per-company limit. Admin/manager roles are unaffected.
        $posUser = auth('pos')->user();
        if ($posUser && ($posUser->pos_role ?? null) === 'pos_cashier') {
            $cashierMaxPct = (float) ($company->cashier_discount_limit ?? 50);
            if ($request->discount_type === 'percentage' && (float) $request->discount_value > $cashierMaxPct) {
                $request->merge(['discount_value' => $cashierMaxPct]);
            }
        }

        $companyItems = $this->resolveItemExemptions($request->items, $companyId);
        $subtotal = array_sum(array_column($companyItems, 'lineTotal'));
        $taxableSubtotal = array_sum(array_map(fn($i) => $i['isExempt'] ? 0 : $i['lineTotal'], $companyItems));
        $exemptSubtotal = $subtotal - $taxableSubtotal;

        $discountValue = (float) ($request->discount_value ?? 0);
        $discountType = $request->discount_type;
        if ($discountType === 'percentage') {
            $discountAmount = round($subtotal * $discountValue / 100, 2);
        } else {
            // Amount-type cashier guardrail (owner rule, Jul 2026): capped at
            // cashier_discount_limit% of the subtotal — mirrors the percentage rule.
            $maxAmountDiscount = $subtotal;
            if ($posUser && ($posUser->pos_role ?? null) === 'pos_cashier') {
                $maxAmountDiscount = round($subtotal * ((float) ($company->cashier_discount_limit ?? 50)) / 100, 2);
            }
            $discountAmount = min($discountValue, $maxAmountDiscount);
        }

        $afterDiscount = $subtotal - $discountAmount;
        $taxableAfterDiscount = $subtotal > 0 ? round($taxableSubtotal / $subtotal * $afterDiscount, 2) : 0;
        $exemptAfterDiscount = round($afterDiscount - $taxableAfterDiscount, 2);

        $taxRate = PosTaxRule::getRateForMethod($request->payment_method, $company);
        // Tax-Inclusive Pricing (Menu-Rate-Final, owner Jul 2026): when ON, the menu
        // price IS the grand total — included tax is back-calculated per payment
        // method and the header is stored in ex-tax-consistent semantics (see
        // PosTaxMath docblock). Item rows keep the INCLUSIVE menu prices as entered.
        // Column guard: if the snapshot column is missing (prod drift), fall back to
        // exclusive math — never store inclusive prices under exclusive semantics.
        $taxInclusiveColumnExists = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_inclusive');
        $pricingMode = $company->posTaxPricingMode();
        $taxInclusive = $taxInclusiveColumnExists && in_array($pricingMode, ['inclusive', 'inclusive_card_save'], true);
        // Card-save (mode 3, owner Jul 2026): menu prices are inclusive at the CASH
        // rate; the bill's OWN method rate is applied on the derived base — card
        // bills get cheaper. tax_menu_rate SNAPSHOT rides on EVERY mode-3 bill
        // (cash too). Column missing (prod drift) → classic inclusive fallback
        // (menu guarantee, no card saving) — never corrupt the stored split.
        $menuRateColumnExists = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_menu_rate');
        $menuRate = null;
        if ($taxInclusive && $pricingMode === 'inclusive_card_save' && $menuRateColumnExists) {
            $menuRate = (float) PosTaxRule::getRateForMethod('cash', $company);
        }
        if ($taxInclusive) {
            $inc = \App\Services\PosTaxMath::inclusiveHeader((float) $subtotal, (float) $taxableSubtotal, (float) $discountAmount, (float) $taxRate, $menuRate);
            $taxAmount = $inc['tax_amount'];
            $totalAmount = $inc['total_amount'];
            $exemptAfterDiscount = $inc['exempt_amount'];
            $headerSubtotal = $inc['subtotal_col'];
        } else {
            // Round tax to nearest whole rupee — matches frontend Math.round(taxAmount).
            // Pakistan POS convention: tax + bill always whole rupees, no paisa.
            $taxAmount = (float) round($taxableAfterDiscount * $taxRate / 100);
            $totalAmount = (float) round($afterDiscount + $taxAmount);
            $headerSubtotal = $subtotal;
        }
        $taxInclusiveFields = $taxInclusiveColumnExists ? ['tax_inclusive' => $taxInclusive] : [];
        if ($menuRateColumnExists) {
            // NULL for exclusive/classic-inclusive bills; draft-resume overwrites too.
            $taxInclusiveFields['tax_menu_rate'] = $menuRate;
        }

        if ($request->terminal_id) {
            $terminal = PosTerminal::where('company_id', $companyId)->where('id', $request->terminal_id)->where('is_active', true)->first();
            if (!$terminal) {
                return back()->withInput()->with('error', __('pos.invalid_inactive_terminal'));
            }
        }

        // PROVISIONAL BILL FLOW — when cashier explicitly saves as provisional, the bill is
        // created with pra_status='local' regardless of company.pra_reporting_enabled, and
        // PRA submission is skipped. Bill remains editable/deletable until promoted to final
        // via retryPra (the "Submit to PRA — Make Final" button on transaction-show).
        $saveAsProvisional = (bool) $request->input('save_as_provisional', false);

        // Order-type flow rules (owner, Jul 2026): on restaurant-ish companies (order-type
        // widget visible = any of tables/kot/kitchen/delivery on), provisional bills are
        // DELIVERY-only — Dine-In uses the Hold/KOT/recall procedure, Takeaway is billed
        // directly as final. Only enforced when the client sent order_type (older queued
        // offline payloads lack it — never strand a replay).
        if ($saveAsProvisional && $request->filled('order_type') && $request->input('order_type') !== 'delivery') {
            $flowFeatures = PosFeatureService::forCompany($company);
            if (($flowFeatures->tables ?? false) || ($flowFeatures->kot ?? false) || ($flowFeatures->kitchen ?? false) || ($flowFeatures->delivery ?? false)) {
                $flowMsg = __('pos.provisional_delivery_only_flow');
                if ($request->expectsJson()) {
                    return response()->json(['success' => false, 'error' => $flowMsg, 'message' => $flowMsg], 422);
                }
                return back()->withInput()->with('error', $flowMsg);
            }
        }

        // Monthly bill quota (paid-plan package limits, Jul 2026) — FINAL bills only.
        // Provisionals stay allowed; they consume quota when promoted to final.
        if (!$saveAsProvisional) {
            $quota = \App\Services\PlanLimitService::canCreatePosBill($companyId);
            if (!($quota['allowed'] ?? true)) {
                if ($request->expectsJson()) {
                    // Task 216: flag the quota gate + whether a provisional retry would pass
                    // the flow rules (delivery-only on restaurant-ish companies — mirrors the
                    // $saveAsProvisional gate above). The sale screen offers a one-click
                    // "save as provisional" retry instead of a dead-end error.
                    $flowFeatures = PosFeatureService::forCompany($company);
                    $restaurantish = ($flowFeatures->tables ?? false) || ($flowFeatures->kot ?? false) || ($flowFeatures->kitchen ?? false) || ($flowFeatures->delivery ?? false);
                    $provisionalAllowed = !$restaurantish || !$request->filled('order_type') || $request->input('order_type') === 'delivery';
                    return response()->json([
                        'success' => false,
                        'error' => $quota['reason'],
                        'message' => $quota['reason'],
                        'quota_full' => true,
                        'provisional_allowed' => $provisionalAllowed,
                    ], 403);
                }
                return back()->withInput()->with('error', $quota['reason']);
            }
        }

        // Per-cashier toggle (owner rule Jul 2026): the ACTING user's own reporting
        // switch decides this bill's fate — never another cashier's or (once the user
        // has personally toggled) the company-wide flag.
        $praEnabled = $posUser->praReportingEnabled($company) && !$saveAsProvisional;
        if ($saveAsProvisional) {
            // Deliberate provisional — editable/deletable via F10 Local modal until promoted.
            $invoiceMode = 'local';
            $initialPraStatus = 'local';
        } elseif ($praEnabled) {
            $invoiceMode = 'pra';
            $initialPraStatus = 'pending';
        } else {
            // PRA-reporting-OFF company, FINAL sale. Must NOT be 'local' — local mode
            // hides the bill from the normal panel (transactions/KPIs/reports filter to
            // pra/NULL) and pollutes the F10 provisional modal where cashiers could
            // edit/delete a final bill. 'pra' mode + NULL pra_status = normal bill with
            // no PRA involvement (agent/failed/retry lists all key off pra_status).
            $invoiceMode = 'pra';
            $initialPraStatus = null;
        }

        // Delivery Riders (Jul 2026): snapshot order_type + optional rider on the
        // bill. Rider only rides on Delivery orders; validated company-scoped +
        // active (invalid ids silently dropped — never block a payment). Purely
        // additive — the three-branch invoice_mode logic above is untouched.
        $riderColumnsExist = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_id');
        $orderTypeSnapshot = $request->filled('order_type')
            ? substr((string) $request->input('order_type'), 0, 20)
            : null;
        $riderId = null;
        // Plan gate: riders is Pro+ — silently drop rider_id (NEVER reject the
        // bill itself: offline replays from a downgraded shop must still land).
        if ($riderColumnsExist && $orderTypeSnapshot === 'delivery' && $request->filled('rider_id')
            && PosFeatureService::planAllows($company, 'riders_enabled')) {
            $riderId = \App\Models\PosRider::where('company_id', $companyId)
                ->where('id', (int) $request->input('rider_id'))
                ->where('is_active', true)
                ->value('id');
        }
        $riderFields = $riderColumnsExist ? [
            'order_type' => $orderTypeSnapshot,
            'rider_id' => $riderId,
            'delivery_status' => $riderId ? 'assigned' : null,
        ] : [];

        // Offline Desktop Mode Phase 2 (Jul 2026): honor the ORIGINAL sale moment
        // and cashier for offline-queued bills. Only trusted when the request also
        // carries an offline_uuid (i.e. it really came through the offline queue).
        // Timestamp is clamped to [now-3d, now] — a wrong PC clock or a stale
        // queue can never back-date beyond the wash window or post-date a bill.
        // Attribution only sticks when the claimed user belongs to THIS company.
        $offlineQueuedAt = null;
        $offlineQueuedBy = null;
        if ($offlineUuidColumnExists) {
            if ($request->filled('offline_queued_at')) {
                try {
                    $qa = \Carbon\Carbon::parse($request->input('offline_queued_at'));
                    $min = now()->subDays(3);
                    if ($qa->lt($min)) {
                        $qa = $min;
                    }
                    if ($qa->gt(now())) {
                        $qa = now();
                    }
                    $offlineQueuedAt = $qa;
                } catch (\Throwable $e) {
                    $offlineQueuedAt = null;
                }
            }
            if ($request->filled('offline_queued_by')) {
                $qbId = (int) $request->input('offline_queued_by');
                if ($qbId > 0 && $qbId !== (int) auth('pos')->id()) {
                    $offlineQueuedBy = \App\Models\User::where('id', $qbId)
                        ->where('company_id', $companyId)
                        ->value('id');
                }
            }
        }
        // Multi-branch fidelity (Jul 2026): a bill queued offline on branch A
        // must book under branch A even if the sync happens under a different
        // login/branch context. Only accepted when the branch belongs to THIS
        // company; otherwise falls back to the current session's branch.
        $offlineBranchId = null;
        if ($offlineUuidColumnExists && $request->filled('offline_branch_id')) {
            $obId = (int) $request->input('offline_branch_id');
            if ($obId > 0) {
                $offlineBranchId = \App\Models\Branch::where('company_id', $companyId)
                    ->where('id', $obId)
                    ->value('id');
            }
        }

        DB::beginTransaction();
        try {
            $draftId = $request->input('draft_id');
            $transaction = null;

            if ($draftId) {
                $transaction = PosTransaction::where('company_id', $companyId)
                    ->where('id', $draftId)
                    ->where('status', 'draft')
                    ->lockForUpdate()
                    ->first();
            }

            if ($transaction) {
                $invoiceNumber = $transaction->invoice_number;
                // Serial split (owner rule Jul 2026): re-resolve the serial if the
                // reporting toggle changed between draft save and finalize. A PRA-bound
                // final must carry a POS fiscal serial (PRA must never receive an
                // L-NNN USIN), and a non-PRA bill must not hold a fiscal serial.
                $isPosSerial = str_starts_with($invoiceNumber, 'POS-');
                if ($praEnabled && !$isPosSerial) {
                    $invoiceNumber = $this->generateInvoiceNumber($companyId);
                } elseif (!$praEnabled && $isPosSerial) {
                    $invoiceNumber = $this->generateLocalInvoiceNumber($companyId);
                }
                $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

                $transaction->update([
                    'invoice_number' => $invoiceNumber,
                    'terminal_id' => $request->terminal_id,
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone,
                    'delivery_address' => $request->input('delivery_address') ?: null,
                    'subtotal' => $headerSubtotal,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'exempt_amount' => $exemptAfterDiscount,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'status' => 'completed',
                    // Keep invoice_mode in sync with the resolved mode so a resumed
                    // draft is never orphaned from the Local / Failed UI lists.
                    'invoice_mode' => $invoiceMode,
                    'pra_status' => $initialPraStatus,
                    'submission_hash' => $submissionHash,
                    'locked_by_terminal_id' => null,
                    'lock_time' => null,
                    'notes' => $request->input('kitchen_notes'),
                ] + $riderFields + $taxInclusiveFields);

                $transaction->items()->delete();
            } else {
                // Serial split (owner rule Jul 2026): POS-YYYY-NNNNN fiscal serials are
                // ONLY for bills actually reported to PRA. Provisionals AND
                // reporting-OFF finals both draw from the L-series — the fiscal
                // sequence must never be consumed by a bill PRA will never see.
                $invoiceNumber = $praEnabled
                    ? $this->generateInvoiceNumber($companyId)
                    : $this->generateLocalInvoiceNumber($companyId);
                $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

                $transaction = PosTransaction::create([
                    'company_id' => $companyId,
                    'branch_id' => $offlineBranchId ?: (app()->bound('currentBranchId') ? app('currentBranchId') : null),
                    'terminal_id' => $request->terminal_id,
                    'invoice_number' => $invoiceNumber,
                    'invoice_mode' => $invoiceMode,
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone,
                    'delivery_address' => $request->input('delivery_address') ?: null,
                    'subtotal' => $headerSubtotal,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'exempt_amount' => $exemptAfterDiscount,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'cash_received' => $request->payment_method === 'cash' ? ($request->cash_received ?: $totalAmount) : null,
                    'change_due' => $request->payment_method === 'cash' && $request->cash_received ? max(0, round($request->cash_received - $totalAmount, 2)) : null,
                    'status' => 'completed',
                    'pra_status' => $initialPraStatus,
                    'submission_hash' => $submissionHash,
                    // Offline-first idempotency key (NULL for normal online bills).
                    'offline_uuid' => $offlineUuidColumnExists ? $offlineUuid : null,
                    // Offline sync: credit the cashier who RANG UP the bill, not
                    // whoever's session replayed the queue next morning.
                    'created_by' => $offlineQueuedBy ?: auth('pos')->id(),
                    'notes' => $request->input('kitchen_notes'),
                ] + $riderFields + $taxInclusiveFields);
            }

            // Offline sync: stamp the bill with the ORIGINAL (clamped) sale moment.
            // created_at is NOT mass-assignable — set + save explicitly. Applies to
            // both the fresh-create and the resumed-draft finalize paths.
            if ($offlineQueuedAt) {
                $transaction->created_at = $offlineQueuedAt;
                $transaction->save();
            }

            foreach ($companyItems as $ri) {
                $itemTaxRate = $ri['isExempt'] ? 0 : $taxRate;
                $itemDiscountShare = $subtotal > 0 ? round($discountAmount * ($ri['lineTotal'] / $subtotal), 2) : 0;
                $itemTaxableAmount = $ri['lineTotal'] - $itemDiscountShare;
                // Inclusive mode: line prices are menu (tax-in) — tax_amount holds the
                // INCLUDED portion, unit_price/subtotal keep the as-entered menu values.
                $itemTaxAmount = $taxInclusive
                    ? \App\Services\PosTaxMath::inclusiveLineTax((float) $itemTaxableAmount, (float) $itemTaxRate, $menuRate)
                    : round($itemTaxableAmount * $itemTaxRate / 100, 2);

                PosTransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'item_type' => $ri['type'],
                    'item_id' => $ri['item_id'],
                    'item_name' => $ri['name'],
                    'special_notes' => $ri['notes'] ?? null,
                    'deal_snapshot' => $ri['deal_snapshot'] ?? null,
                    'quantity' => $ri['quantity'],
                    'unit_price' => $ri['price'],
                    'subtotal' => $ri['lineTotal'],
                    'is_tax_exempt' => $ri['isExempt'],
                    'tax_rate' => $itemTaxRate,
                    'tax_amount' => $itemTaxAmount,
                ]);
            }

            PosPayment::create([
                'transaction_id' => $transaction->id,
                'payment_method' => $request->payment_method,
                'amount' => $totalAmount,
                'reference_number' => $request->reference_number,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $errMsg = __('pos.failed_create_invoice', ['error' => $e->getMessage()]);
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $errMsg], 500);
            }
            return back()->withInput()->with('error', $errMsg);
        }

        // Deduct from the RESOLVED items (not raw request): resolved rows carry the
        // frozen deal_snapshot so deal components move stock too (deal lines
        // themselves are type 'deal' → skipped by the deduction loop).
        $stockItems = $this->expandDealComponentsForStock(array_map(fn ($ri) => [
            'type' => $ri['type'],
            'item_id' => $ri['item_id'],
            'quantity' => (float) $ri['quantity'],
            'unit_price' => (float) $ri['price'],
            'deal_snapshot' => $ri['deal_snapshot'] ?? null,
        ], $companyItems));
        $inventoryResult = PosInventoryController::deductStockForInvoice(
            $companyId,
            $stockItems,
            $transaction->id,
            $invoiceNumber,
            auth('pos')->id()
        );

        // F3 Dine-In (Jul 2026): a table reserved from the universal sale screen is
        // auto-freed the moment its bill is stored (final OR provisional). Only
        // touches status='reserved' — 'occupied' belongs to the held-order lifecycle
        // (payOrder/deleteOrder free those). Freeing must never fail a sale.
        if ($request->filled('table_id')) {
            try {
                RestaurantTable::where('company_id', $companyId)
                    ->where('id', (int) $request->input('table_id'))
                    ->where('status', 'reserved')
                    ->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => null]);
            } catch (\Throwable $e) {
                \Log::warning('Table auto-free failed: ' . $e->getMessage());
            }
        }

        $praMessage = '';
        if ($praEnabled) {
            // ENTERPRISE SAFE MODE — Phase 1: when Agent Sync submission mode is on, skip server-side direct submission.
            // The agent (running on the company's local Pakistani PC) will pick this up via /api/agent/pending-invoices.
            // agentHandlesPra() NOT agent_enabled — Direct Production shops may keep the agent connected for silent printing.
            if ($company->agentHandlesPra()) {
                $transaction->update(['pra_status' => 'pending']);
                $praMessage = __('pos.pra_msg_awaiting_sync');
            } else {
                try {
                    $praService = new PraIntegrationService($company);
                    $praResult = $praService->sendInvoice($transaction);
                    $transaction->refresh();

                    if ($praResult['success']) {
                        $praMessage = __('pos.pra_msg_fiscal_number', ['number' => $transaction->pra_invoice_number ?? 'N/A']);
                    } else {
                        $transaction->update(['pra_status' => 'offline']);
                        $praMessage = __('pos.pra_msg_offline_mode');
                    }
                } catch (\Exception $e) {
                    $transaction->update(['pra_status' => 'offline']);
                    $praMessage = __('pos.pra_msg_offline_mode');
                }
            }
        } else {
            $praMessage = __('pos.pra_msg_local_reporting_off');
        }

        $successMessage = __('pos.invoice_created_success', ['number' => $invoiceNumber, 'pra' => $praMessage]);

        // JSON callers (Universal POS manual-cart bypass) expect a structured
        // response so the receipt modal can render in-place. Legacy form-POST
        // callers (pos/create-invoice.blade.php) continue to receive the
        // traditional redirect-with-flash response.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'transaction_id' => $transaction->id,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAmount,
                'pra_invoice_number' => $transaction->pra_invoice_number ?? null,
                'pra_status' => $transaction->pra_status ?? null,
                'message' => $successMessage,
            ]);
        }

        return redirect()->route('pos.transaction.show', $transaction->id)
            ->with('success', $successMessage);
    }

    public function editTransaction($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with('items')
            ->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', __('pos.cannot_edit_submitted_pra_num', ['number' => $transaction->pra_invoice_number]));
        }

        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->where('show_on_sale', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $posCustomers = PosCustomer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $taxRules = PosTaxRule::effectiveRules($company);
        $terminals = PosTerminal::where('company_id', $companyId)->where('is_active', true)->get();

        $transactionItems = $transaction->items->map(fn($item) => [
            'type' => $item->item_type ?? 'product',
            'item_id' => $item->item_id ?? '',
            'name' => $item->item_name ?? '',
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
        ])->values();

        return view('pos.edit-transaction', compact('company', 'transaction', 'transactionItems', 'products', 'services', 'taxRules', 'terminals', 'posCustomers'));
    }

    public function updateTransaction(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)->with('items')->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => __('pos.cannot_edit_submitted_pra_num', ['number' => $transaction->pra_invoice_number])], 422);
            }
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', __('pos.cannot_edit_submitted_pra'));
        }

        // Snapshot the OLD line items before they are replaced, so the edit can
        // reconcile inventory (restore old qty, deduct new qty) when the owner
        // opted into restock-on-void. Captured now — items are deleted below.
        $restockOnEdit = $company && $company->inventory_enabled && $company->pos_restock_on_void;
        $oldStockItems = $restockOnEdit
            ? $this->expandDealComponentsForStock($transaction->items->map(fn ($i) => [
                'type' => $i->item_type ?? 'product',
                'item_id' => $i->item_id,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'deal_snapshot' => $i->deal_snapshot,
            ])->all())
            : [];

        $request->validate([
            'items' => 'required|array|min:1|max:200',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:9999',
            'items.*.unit_price' => 'required|numeric|min:0|max:10000000',
            'payment_method' => 'required|in:cash,card,debit_card,credit_card,qr_payment',
            'discount_type' => 'required|in:percentage,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'cash_received' => 'nullable|numeric|min:0',
            // Item #1 (Jul 2026): delivery-address SNAPSHOT — frozen on the bill so
            // later edits to the customer's saved addresses never rewrite receipts.
            'delivery_address' => 'nullable|string|max:500',
        ]);

        // Normalize 'card' alias → 'debit_card' (see storeInvoice() for rationale).
        if ($request->input('payment_method') === 'card') {
            $request->merge(['payment_method' => 'debit_card']);
        }

        // Cashier discount guardrail (mirrors RestaurantPosController::holdOrder
        // and storeInvoice). Stops a `pos_cashier` from bypassing the per-company
        // percentage limit through the edit/update path.
        $posUser = auth('pos')->user();
        if ($posUser && ($posUser->pos_role ?? null) === 'pos_cashier') {
            $cashierMaxPct = (float) ($company->cashier_discount_limit ?? 50);
            if ($request->discount_type === 'percentage' && (float) $request->discount_value > $cashierMaxPct) {
                $request->merge(['discount_value' => $cashierMaxPct]);
            }
        }

        $companyItems = $this->resolveItemExemptions($request->items, $companyId);
        $subtotal = array_sum(array_column($companyItems, 'lineTotal'));
        $taxableSubtotal = array_sum(array_map(fn($i) => $i['isExempt'] ? 0 : $i['lineTotal'], $companyItems));

        $discountValue = (float) ($request->discount_value ?? 0);
        $discountType = $request->discount_type;
        if ($discountType === 'percentage') {
            $discountAmount = round($subtotal * $discountValue / 100, 2);
        } else {
            // Amount-type cashier guardrail (owner rule, Jul 2026): capped at
            // cashier_discount_limit% of the subtotal — mirrors the percentage rule.
            $maxAmountDiscount = $subtotal;
            if ($posUser && ($posUser->pos_role ?? null) === 'pos_cashier') {
                $maxAmountDiscount = round($subtotal * ((float) ($company->cashier_discount_limit ?? 50)) / 100, 2);
            }
            $discountAmount = min($discountValue, $maxAmountDiscount);
        }

        $afterDiscount = $subtotal - $discountAmount;
        $taxableAfterDiscount = $subtotal > 0 ? round($taxableSubtotal / $subtotal * $afterDiscount, 2) : 0;
        $exemptAfterDiscount = round($afterDiscount - $taxableAfterDiscount, 2);

        $taxRate = PosTaxRule::getRateForMethod($request->payment_method, $company);
        // Tax-Inclusive Pricing: an EDIT branches on the bill's SNAPSHOT flag, never
        // the current company setting — the bill keeps the semantics it was created
        // under (stored item prices are menu/inclusive for inclusive bills).
        $editTaxInclusive = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_inclusive')
            && (bool) $transaction->tax_inclusive;
        // Card-save (mode 3): the MENU rate rides on the bill's SNAPSHOT —
        // never re-read company config on an edit (rates are mutable).
        $editMenuRate = $editTaxInclusive
            && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_menu_rate')
            && $transaction->tax_menu_rate !== null
            ? (float) $transaction->tax_menu_rate
            : null;
        if ($editTaxInclusive) {
            $inc = \App\Services\PosTaxMath::inclusiveHeader((float) $subtotal, (float) $taxableSubtotal, (float) $discountAmount, (float) $taxRate, $editMenuRate);
            $taxAmount = $inc['tax_amount'];
            $totalAmount = $inc['total_amount'];
            $exemptAfterDiscount = $inc['exempt_amount'];
            $headerSubtotal = $inc['subtotal_col'];
        } else {
            // Round tax to nearest whole rupee — matches frontend Math.round(taxAmount).
            // Pakistan POS convention: tax + bill always whole rupees, no paisa.
            $taxAmount = (float) round($taxableAfterDiscount * $taxRate / 100);
            $totalAmount = (float) round($afterDiscount + $taxAmount);
            $headerSubtotal = $subtotal;
        }

        // Owner rule (Jul 2026 update): an EDIT must NEVER silently change a bill's
        // reporting fate. The bill keeps whatever it was:
        //   provisional stays provisional; a LOCAL final (NULL pra_status, no fiscal)
        //   stays local UNLESS the editor explicitly ticked "Report to PRA";
        //   a PRA-pipeline bill (pending/failed/offline) stays in the pipeline.
        $posEditUser = auth('pos')->user();
        $isProvisionalEdit = ($transaction->invoice_mode === 'local' && $transaction->pra_status === 'local');
        $isLocalFinalEdit = !$isProvisionalEdit && $transaction->pra_status === null;
        $isPipelineEdit = !$isProvisionalEdit && !$isLocalFinalEdit;
        $reportRequested = $isLocalFinalEdit && $request->boolean('report_to_pra');

        if ($reportRequested) {
            // Explicit promote-on-save: admin-only + current month only — the exact
            // same gates as the per-bill "Submit to PRA" promote paths.
            if (!$posEditUser?->isPosAdmin()) {
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => __('pos.only_pos_admin_report_local_pra')], 403);
                }
                return back()->withInput()->with('error', __('pos.only_pos_admin_report_local_pra'));
            }
            if ($transaction->created_at && $transaction->created_at->lt(now()->startOfMonth())) {
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => __('pos.only_current_month_local_pra_short')], 422);
                }
                return back()->withInput()->with('error', __('pos.only_current_month_local_pra'));
            }
        }

        $goingToPra = $isPipelineEdit || $reportRequested;
        // Serial split: a bill headed to PRA must carry a real POS fiscal serial
        // (PRA must never receive an L-NNN USIN). A local bill KEEPS its L number;
        // a POS-serial bill never renumbers downward.
        $invoiceNumberEdit = $transaction->invoice_number;
        if ($goingToPra && !str_starts_with($invoiceNumberEdit, 'POS-')) {
            $invoiceNumberEdit = $this->generateInvoiceNumber($companyId);
        }

        DB::beginTransaction();
        try {
            $transaction->update([
                'invoice_number' => $invoiceNumberEdit,
                'terminal_id' => $request->terminal_id,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'delivery_address' => $request->input('delivery_address') ?: null,
                'subtotal' => $headerSubtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'exempt_amount' => $exemptAfterDiscount,
                'total_amount' => $totalAmount,
                'payment_method' => $request->payment_method,
                // Three-branch invariant, fate preserved (owner rule Jul 2026):
                // provisional stays provisional; a bill going to PRA (pipeline bill
                // or explicit "Report to PRA" tick) re-queues as 'pending'; a local
                // final keeps NULL (never regress to 'local' — that would hide it
                // from transactions/KPIs and expose it in the F10 modal).
                'pra_status' => $isProvisionalEdit
                    ? 'local'
                    : ($goingToPra ? 'pending' : null),
                'notes' => $request->input('kitchen_notes'),
            ]);

            $transaction->items()->delete();

            foreach ($companyItems as $ri) {
                $itemTaxRate = $ri['isExempt'] ? 0 : $taxRate;
                $itemDiscountShare = $subtotal > 0 ? round($discountAmount * ($ri['lineTotal'] / $subtotal), 2) : 0;
                $itemTaxableAmount = $ri['lineTotal'] - $itemDiscountShare;
                $itemTaxAmount = $editTaxInclusive
                    ? \App\Services\PosTaxMath::inclusiveLineTax((float) $itemTaxableAmount, (float) $itemTaxRate, $editMenuRate)
                    : round($itemTaxableAmount * $itemTaxRate / 100, 2);

                PosTransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'item_type' => $ri['type'],
                    'item_id' => $ri['item_id'],
                    'item_name' => $ri['name'],
                    'special_notes' => $ri['notes'] ?? null,
                    'deal_snapshot' => $ri['deal_snapshot'] ?? null,
                    'quantity' => $ri['quantity'],
                    'unit_price' => $ri['price'],
                    'subtotal' => $ri['lineTotal'],
                    'is_tax_exempt' => $ri['isExempt'],
                    'tax_rate' => $itemTaxRate,
                    'tax_amount' => $itemTaxAmount,
                ]);
            }

            $transaction->payments()->delete();
            PosPayment::create([
                'transaction_id' => $transaction->id,
                'payment_method' => $request->payment_method,
                'amount' => $totalAmount,
                'reference_number' => $request->reference_number,
            ]);

            // Reconcile inventory for the edit: put the old quantities back, then
            // deduct the new quantities. Net effect keeps stock true to the bill
            // as it now stands (only when restock-on-void is enabled).
            if ($restockOnEdit) {
                PosInventoryController::restoreStockForInvoice(
                    $companyId, $oldStockItems, $transaction->id, $transaction->invoice_number, auth('pos')->id(), 'pos_edit'
                );
                $newStockItems = $this->expandDealComponentsForStock(array_map(fn ($ri) => [
                    'type' => $ri['type'],
                    'item_id' => $ri['item_id'],
                    'quantity' => (float) $ri['quantity'],
                    'unit_price' => (float) $ri['price'],
                    'deal_snapshot' => $ri['deal_snapshot'] ?? null,
                ], $companyItems));
                PosInventoryController::deductStockForInvoice(
                    $companyId, $newStockItems, $transaction->id, $transaction->invoice_number, auth('pos')->id()
                );
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => __('pos.failed_update_invoice', ['error' => $e->getMessage()])], 500);
            }
            return back()->withInput()->with('error', __('pos.failed_update_invoice', ['error' => $e->getMessage()]));
        }

        $praMessage = '';
        if ($goingToPra) {
            // ENTERPRISE SAFE MODE — Phase 1: Agent-Sync companies bypass server-side submission.
            if ($company->agentHandlesPra()) {
                $transaction->update(['pra_status' => 'pending', 'pra_response_code' => null]);
                $praMessage = ' | 🟡 Awaiting Sync (desktop agent).';
            } else {
                try {
                    $transaction->update(['pra_status' => 'pending', 'pra_response_code' => null]);
                    $praService = new PraIntegrationService($company);
                    $praResult = $praService->sendInvoice($transaction);
                    $transaction->refresh();

                    if ($praResult['success']) {
                        $praMessage = __('pos.pra_msg_fiscal_num_short', ['number' => $transaction->pra_invoice_number ?? 'N/A']);
                    } else {
                        $transaction->update(['pra_status' => 'offline']);
                        $praMessage = __('pos.pra_msg_offline_will_sync');
                    }
                } catch (\Exception $e) {
                    $transaction->update(['pra_status' => 'offline']);
                    $praMessage = __('pos.pra_msg_offline_will_sync');
                }
            }
        }

        // Sale-screen edit mode (Kaam 5, Jul 2026): JSON callers get a JSON result —
        // the sale screen shows a toast + reloads clean instead of following redirects.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'invoice_number' => $transaction->invoice_number,
                'total_amount' => (float) $transaction->total_amount,
                'message' => __('pos.invoice_updated_success', ['pra' => $praMessage]),
            ]);
        }

        // Edited from the sale screen (F10/F11 modals pass from=sale) → return the cashier
        // straight back to the sale screen instead of the transaction detail page.
        if ($request->input('from') === 'sale') {
            return redirect()->route('pos.invoice.create')
                ->with('success', __('pos.invoice_updated_success', ['pra' => $praMessage]));
        }

        return redirect()->route('pos.transaction.show', $transaction->id)
            ->with('success', __('pos.invoice_updated_success', ['pra' => $praMessage]));
    }

    public function deleteTransaction($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // Cashiers may create sales and finalize provisional bills, but NEVER delete —
        // deletion is a company-admin decision (owner rule Jul 2026).
        $posUser = auth('pos')->user();
        if ($posUser && $posUser->isPosCashier()) {
            return back()->with('error', __('pos.no_permission_delete_bill'));
        }

        $transaction = PosTransaction::where('company_id', $companyId)->with('items')->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', __('pos.cannot_delete_submitted_pra_num', ['number' => $transaction->pra_invoice_number]));
        }

        DB::beginTransaction();
        try {
            // Return the sold stock to inventory before the items disappear —
            // only when tracking is on AND the owner opted into restock-on-void.
            if ($company && $company->inventory_enabled && $company->pos_restock_on_void) {
                $restoreItems = $this->expandDealComponentsForStock($transaction->items->map(fn ($i) => [
                    'type' => $i->item_type ?? 'product',
                    'item_id' => $i->item_id,
                    'quantity' => (float) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'deal_snapshot' => $i->deal_snapshot,
                ])->all());
                PosInventoryController::restoreStockForInvoice(
                    $companyId, $restoreItems, $transaction->id, $transaction->invoice_number, auth('pos')->id(), 'pos_void'
                );
            }

            $transaction->items()->delete();
            $transaction->payments()->delete();
            $transaction->praLogs()->delete();
            $transaction->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', __('pos.failed_delete_invoice', ['error' => $e->getMessage()]));
        }

        return redirect()->route('pos.transactions')
            ->with('success', __('pos.invoice_deleted_success', ['number' => $transaction->invoice_number]));
    }

    /**
     * Race-safe local→final promotion for retryPra: locks + re-verifies the bill is
     * still a genuine provisional (triple completed/local/local) INSIDE the transaction
     * before allotting the POS serial — a double-POST (or a race with
     * apiPromoteProvisional) must never renumber twice or clobber a bill already
     * queued/submitted to PRA. Returns false when the bill is no longer promotable.
     * $newPraStatus: 'pending' (reporting ON, will submit) or null (reporting OFF final).
     */
    private function promoteLocalToPosSerial(PosTransaction $transaction, int $companyId, ?string $newPraStatus): bool
    {
        try {
            DB::transaction(function () use ($transaction, $companyId, $newPraStatus) {
                $locked = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->where('id', $transaction->id)
                    ->lockForUpdate()
                    ->first();

                if (!$locked || $locked->pra_status !== 'local' || $locked->status !== 'completed' || $locked->invoice_mode !== 'local') {
                    throw new \RuntimeException('NOT_PROVISIONAL');
                }

                $locked->update([
                    // Serial split (owner rule Jul 2026): the L-series number changes
                    // to a real POS fiscal serial ONLY when the bill actually goes to
                    // PRA ('pending'). A reporting-OFF finalize keeps its L number —
                    // fiscal serials are reserved for PRA-reported bills.
                    'invoice_number' => $newPraStatus !== null
                        ? $this->generateInvoiceNumber($companyId)
                        : $locked->invoice_number,
                    'pra_status' => $newPraStatus,
                    'invoice_mode' => 'pra',
                    'pra_response_code' => null,
                    // Un-archive: a promoted bill is a live PRA bill and must appear
                    // on the normal POS surfaces.
                    'is_archived' => false,
                    'archived_at' => null,
                ]);
            });
        } catch (\RuntimeException $e) {
            return false;
        }

        $transaction->refresh();
        return true;
    }

    /**
     * Race-safe promote for a reporting-OFF FINAL (completed, NULL pra_status, no
     * fiscal) heading to PRA via the per-bill "Submit to PRA" button (owner rule
     * Jul 2026 update). Locks + re-verifies inside the transaction, allots a POS
     * fiscal serial only when the bill still carries an L-series number (a POS-
     * serial bill never renumbers), queues as 'pending' and un-archives.
     * Returns false when the bill is no longer a promotable local final.
     */
    private function promoteNullFinalToPra(PosTransaction $transaction, int $companyId): bool
    {
        try {
            DB::transaction(function () use ($transaction, $companyId) {
                $locked = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->where('id', $transaction->id)
                    ->lockForUpdate()
                    ->first();

                if (!$locked || $locked->pra_status !== null || $locked->status !== 'completed' || $locked->pra_invoice_number) {
                    throw new \RuntimeException('NOT_LOCAL_FINAL');
                }

                $locked->update([
                    'invoice_number' => str_starts_with($locked->invoice_number, 'POS-')
                        ? $locked->invoice_number
                        : $this->generateInvoiceNumber($companyId),
                    'pra_status' => 'pending',
                    'invoice_mode' => 'pra',
                    'pra_response_code' => null,
                    'is_archived' => false,
                    'archived_at' => null,
                ]);
            });
        } catch (\RuntimeException $e) {
            return false;
        }

        $transaction->refresh();
        return true;
    }

    public function retryPra($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // Archived LOCAL-category bills (day-close archive) must stay reachable for
        // the per-bill "Submit to PRA" promote — mirror the receipt()/transactionShow()
        // limited bypass: archived rows only pass when they are local-category.
        $retryQuery = PosTransaction::where('company_id', $companyId);
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_archived')) {
            $retryQuery = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where(function ($q) {
                    $q->where('is_archived', false)
                        ->orWhereNull('is_archived')
                        ->orWhere('invoice_mode', 'local')
                        ->orWhereNull('pra_status');
                });
        }
        $transaction = $retryQuery->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return back()->with('error', __('pos.invoice_already_submitted_pra_num', ['number' => $transaction->pra_invoice_number]));
        }

        if ($transaction->pra_status === 'submitted') {
            return back()->with('error', __('pos.invoice_already_submitted_pra'));
        }

        // Reporting-OFF final = LOCAL-category bill: completed, NULL pra_status,
        // no fiscal. Owner rule (Jul 2026 update): these get a per-bill
        // "Submit to PRA" too — current month only.
        $isNullFinal = $transaction->pra_status === null && $transaction->status === 'completed';

        // Provisional ('local') bills CAN be promoted to final — this is the
        // "Submit to PRA — Make Final" path. They will be re-queued as 'pending'
        // and submitted just like any pending/failed/offline retry.
        if (!in_array($transaction->pra_status, ['pending', 'failed', 'offline', 'local']) && !$isNullFinal) {
            return back()->with('error', __('pos.invoice_cannot_submit_status', ['status' => $transaction->pra_status]));
        }

        if ($isNullFinal) {
            // Admin-only (matches Local tab visibility) + MONTH GATE. NO quota
            // re-charge — reporting-OFF finals already consumed quota at creation.
            if (!auth('pos')->user()?->isPosAdmin()) {
                return back()->with('error', __('pos.only_pos_admin_report_local_pra'));
            }
            if ($transaction->created_at && $transaction->created_at->lt(now()->startOfMonth())) {
                return back()->with('error', __('pos.only_current_month_local_pra'));
            }
        }

        // Monthly bill quota (paid-plan package limits, Jul 2026): promoting a
        // provisional here creates a NEW final — same gate as storeInvoice /
        // apiPromoteProvisional. Plain retries of already-final failed/offline/
        // pending bills are NOT re-charged (they consumed quota at creation).
        if ($transaction->pra_status === 'local') {
            $quota = \App\Services\PlanLimitService::canCreatePosBill($companyId);
            if (!($quota['allowed'] ?? true)) {
                return back()->with('error', $quota['reason']);
            }
            // MONTH GATE (owner rule Jul 2026): only CURRENT-MONTH local bills may be
            // promoted to PRA — previous months are closed (report/view only).
            if ($transaction->created_at && $transaction->created_at->lt(now()->startOfMonth())) {
                return back()->with('error', __('pos.only_current_month_local_pra'));
            }
        }

        if (!auth('pos')->user()?->praReportingEnabled($company)) {
            // PRA-reporting-OFF user promoting a provisional → finalize WITHOUT any
            // PRA submission: 'pra' mode + NULL pra_status = normal final bill.
            if ($transaction->pra_status === 'local') {
                if (!$this->promoteLocalToPosSerial($transaction, $companyId, null)) {
                    return back()->with('error', __('pos.bill_no_longer_provisional'));
                }
                return back()->with('success', __('pos.bill_now_final_pra_off', ['number' => $transaction->invoice_number]));
            }
            if (!$isNullFinal) {
                return back()->with('error', __('pos.pra_reporting_disabled_enable'));
            }
            // NULL final + explicit per-bill "Submit to PRA": the admin's personal
            // reporting toggle does NOT block a deliberate submit — fall through.
        }

        // Promoting a provisional bill to final — flip mode + status before submission so
        // generators / templates treat it as a real PRA invoice from this point onward.
        if ($transaction->pra_status === 'local') {
            if (!$this->promoteLocalToPosSerial($transaction, $companyId, 'pending')) {
                return back()->with('error', __('pos.bill_no_longer_provisional'));
            }
        } elseif ($isNullFinal) {
            // Reporting-OFF final going to PRA: allot a real POS fiscal serial
            // (keeps a POS- serial if it already has one — never renumber downward)
            // and queue as 'pending'. Race-safe: locked + re-verified inside.
            if (!$this->promoteNullFinalToPra($transaction, $companyId)) {
                return back()->with('error', __('pos.bill_no_longer_local_final'));
            }
        }

        // ENTERPRISE SAFE MODE — Phase 1: Agent-Sync companies just re-queue; the agent polls every 10s.
        if ($company->agentHandlesPra()) {
            $transaction->update(['pra_status' => 'pending', 'pra_response_code' => null]);
            return back()->with('success', __('pos.requeued_desktop_agent'));
        }

        try {
            $praService = new PraIntegrationService($company);
            $praResult = $praService->sendInvoice($transaction);
            $transaction->refresh();

            if ($praResult['success']) {
                return back()->with('success', __('pos.pra_submission_successful_num', ['number' => $transaction->pra_invoice_number ?? 'N/A']));
            } else {
                return back()->with('error', __('pos.pra_submission_failed', ['error' => $praResult['message'] ?? __('pos.unknown_error')]));
            }
        } catch (\Exception $e) {
            $transaction->update(['pra_status' => 'offline']);
            return back()->with('error', __('pos.pra_connection_failed_sync'));
        }
    }

    public function bulkRetryPra()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (!auth('pos')->user()?->praReportingEnabled($company)) {
            return back()->with('error', __('pos.pra_reporting_disabled_enable'));
        }

        $pendingInvoices = PosTransaction::where('company_id', $companyId)
            ->whereIn('pra_status', ['failed', 'offline', 'pending'])
            ->whereNull('pra_invoice_number')
            ->orderBy('id', 'asc')
            ->get();

        if ($pendingInvoices->isEmpty()) {
            return back()->with('info', __('pos.no_failed_offline_retry'));
        }

        // ENTERPRISE SAFE MODE — Phase 1: Agent-Sync companies just bulk re-queue; the agent will pick them up.
        if ($company->agentHandlesPra()) {
            $count = $pendingInvoices->count();
            DB::table('pos_transactions')
                ->where('company_id', $companyId)
                ->whereIn('id', $pendingInvoices->pluck('id'))
                ->update(['pra_status' => 'pending', 'pra_response_code' => null, 'updated_at' => now()]);
            return back()->with('success', __('pos.invoices_requeued_agent', ['count' => $count]));
        }

        $praService = new PraIntegrationService($company);
        $successCount = 0;
        $failCount = 0;
        $errors = [];

        foreach ($pendingInvoices as $transaction) {
            try {
                $result = $praService->sendInvoice($transaction);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failCount++;
                    $errors[] = $transaction->invoice_number . ': ' . ($result['message'] ?? __('pos.unknown_error'));
                }
            } catch (\Exception $e) {
                $failCount++;
                $transaction->update(['pra_status' => 'offline']);
                $errors[] = $transaction->invoice_number . ': ' . __('pos.connection_failed_word');
            }
        }

        $message = '';
        if ($successCount > 0) {
            $message = __('pos.invoices_submitted_pra', ['count' => $successCount]);
        }
        if ($failCount > 0) {
            $errorDetail = __('pos.invoices_failed_count', ['count' => $failCount]);
            if ($successCount > 0) {
                return back()->with('warning', $message . ' ' . $errorDetail);
            }
            return back()->with('error', $errorDetail . ' ' . implode(' | ', array_slice($errors, 0, 3)));
        }

        return back()->with('success', $message);
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * PROVISIONAL BILLS API — header shortcut endpoints (universal POS).
     * Returns lightweight JSON payload of all bills with pra_status='local'
     * for the current company. Used by the "Local" header button + F10 modal.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function apiProvisionalBills(Request $request)
    {
        $companyId = app('currentCompanyId');
        // Provisional list MUST mirror the Transactions "Local" tab definition:
        // a deliberately-saved provisional bill is a COMPLETED sale in local mode.
        // Filtering on all three keeps drafts (status='draft', invoice_mode='pra')
        // out of this list — those were polluting the F10 modal before.
        $hasRiderCols = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_id')
            && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_settlement_id');
        $hasBizDate = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'business_date');
        $bills = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('invoice_mode', 'local')
            ->where('pra_status', 'local')
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get(['id', 'invoice_number', 'customer_name', 'customer_phone', 'order_type', 'delivery_address', 'total_amount', 'payment_method', 'created_at',
                   ...(\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'kot_sent_at') ? ['kot_sent_at'] : []),
                   ...($hasRiderCols ? ['rider_id', 'rider_settlement_id'] : []),
                   ...($hasBizDate ? ['business_date'] : [])]);

        // ─── FINAL delivery bills still OPEN (owner bug report, 3 Aug 2026) ───
        // Ginti ka farq: sale-screen Pending Deliveries popup sirf provisionals
        // ginta tha jabke rider app / rider khata FINAL delivery bills bhi
        // ginte hain (popup 1 vs khata 2). Yahan wohi final bills add hote hain
        // jo abhi deliver nahi hue, ya deliver ho kar bhi cash rider ke khaate
        // par hai. Display + delivered-mark/settle ONLY — promote (Final
        // Cash/Card) in par KABHI nahi chalta (bill pehle se final hai).
        $finalBills = collect();
        $hasDelStatus = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'delivery_status');
        if ($hasRiderCols && $hasDelStatus) {
            $finalBills = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('status', 'completed')
                ->whereNotNull('rider_id')
                // NOT a provisional (local+local triple = provisional definition).
                ->whereNot(function ($q) {
                    $q->where('invoice_mode', 'local')->where('pra_status', 'local');
                })
                ->where(function ($q) {
                    // Abhi raste mein…
                    $q->whereIn('delivery_status', ['assigned', 'dispatched'])
                        // …ya deliver ho gaya par cash abhi rider ke paas.
                        ->orWhere(function ($q2) {
                            $q2->where('delivery_status', 'delivered')
                               ->where('payment_method', 'cash')
                               ->whereNull('rider_settlement_id');
                        });
                })
                ->orderBy('id', 'desc')
                ->limit(50)
                ->get(['id', 'invoice_number', 'customer_name', 'customer_phone', 'order_type', 'delivery_address', 'total_amount', 'payment_method', 'created_at', 'rider_id', 'rider_settlement_id', 'delivery_status',
                       ...($hasBizDate ? ['business_date'] : [])]);
        }

        // Rider names — one batch lookup for the Pending Deliveries panel (rider
        // warning: "bill Asgar ke khaate mein hai"). Riders NEVER touch
        // invoice_mode/serials; this is display-only context.
        $riderNames = [];
        $riderOpen = []; // rider_id => ['count' => n, 'amount' => rs] — WHOLE khata
        if ($hasRiderCols && \Illuminate\Support\Facades\Schema::hasTable('pos_riders')) {
            $riderIds = $bills->pluck('rider_id')->merge($finalBills->pluck('rider_id'))->filter()->unique();
            if ($riderIds->isNotEmpty()) {
                $riderNames = \DB::table('pos_riders')
                    ->where('company_id', $companyId)
                    ->whereIn('id', $riderIds)
                    ->pluck('name', 'id')
                    ->all();
                // Open khata per rider (Task 123): the panel's Settle button settles
                // the rider's ENTIRE khata (all dates, archived included) — same
                // scope as PosRiderController::settle with settle_all. Show the
                // cashier the real count+amount so "poore rider ka settle" is clear.
                $riderOpen = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->whereIn('rider_id', $riderIds)
                    ->where('payment_method', 'cash')
                    ->whereNull('rider_settlement_id')
                    ->where(function ($q) {
                        $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
                    })
                    ->selectRaw('rider_id, COUNT(*) as c, COALESCE(SUM(total_amount),0) as amt')
                    ->groupBy('rider_id')
                    ->get()
                    ->mapWithKeys(fn ($r) => [$r->rider_id => ['count' => (int) $r->c, 'amount' => (float) $r->amt]])
                    ->all();
            }
        }

        // "Payment First, Then KOT" v2 (Aug 2026): with the company toggle ON, a
        // delivery provisional whose kitchen ticket hasn't fired yet shows a
        // "Send KOT" button in F10 — cashier fires it the moment payment confirms,
        // hours before the bill is made final at night.
        $kotAfterPayment = (bool) (Company::find($companyId)?->delivery_kot_after_payment ?? false);

        $data = $bills->map(function ($b) use ($kotAfterPayment, $hasRiderCols, $hasBizDate, $riderNames, $riderOpen) {
            return [
                'id'               => $b->id,
                'invoice_number'   => $b->invoice_number,
                'customer_name'    => $b->customer_name,
                'customer_phone'   => $b->customer_phone,
                'order_type'       => $b->order_type,
                'delivery_address' => $b->delivery_address,
                'total_amount'     => (float) $b->total_amount,
                'payment_method'   => $b->payment_method,
                'items_count'      => PosTransactionItem::where('transaction_id', $b->id)->count(),
                'created_human'    => $b->created_at?->diffForHumans(),
                'created_at'       => $b->created_at?->toDateTimeString(),
                'created_time'     => $b->created_at?->format('h:i A'),
                'kot_pending'      => $kotAfterPayment && $b->order_type === 'delivery' && empty($b->kot_sent_at),
                // Pending Deliveries panel (Task 114): business_date so the badge
                // counts only TODAY's business day; rider context for the warning.
                'business_date'    => $hasBizDate ? ($b->business_date ? (string) $b->business_date : null) : null,
                'rider_name'       => ($hasRiderCols && $b->rider_id) ? ($riderNames[$b->rider_id] ?? null) : null,
                // Unsettled = still on the rider's khata (cash not handed in yet).
                'rider_unsettled'  => $hasRiderCols && $b->rider_id && empty($b->rider_settlement_id),
                // Task 123: whole-khata scope for the panel's Settle button.
                'rider_id'         => $hasRiderCols ? ($b->rider_id ? (int) $b->rider_id : null) : null,
                'rider_open_count' => ($hasRiderCols && $b->rider_id) ? ($riderOpen[$b->rider_id]['count'] ?? 0) : 0,
                'rider_open_amount'=> ($hasRiderCols && $b->rider_id) ? ($riderOpen[$b->rider_id]['amount'] ?? 0) : 0,
            ];
        });

        // Open FINAL delivery bills — same shape as provisionals + is_final flag
        // + delivery_status (panel inhe alag actions deta hai: Delivered mark /
        // khata settle; Final Cash/Card buttons in par render hi nahi hote).
        $finalData = $finalBills->map(function ($b) use ($hasBizDate, $riderNames, $riderOpen) {
            return [
                'id'               => $b->id,
                'is_final'         => true,
                'invoice_number'   => $b->invoice_number,
                'customer_name'    => $b->customer_name,
                'customer_phone'   => $b->customer_phone,
                'order_type'       => $b->order_type,
                'delivery_address' => $b->delivery_address,
                'total_amount'     => (float) $b->total_amount,
                'payment_method'   => $b->payment_method,
                'items_count'      => PosTransactionItem::where('transaction_id', $b->id)->count(),
                'created_human'    => $b->created_at?->diffForHumans(),
                'created_time'     => $b->created_at?->format('h:i A'),
                'business_date'    => $hasBizDate ? ($b->business_date ? (string) $b->business_date : null) : null,
                'delivery_status'  => $b->delivery_status,
                'rider_id'         => $b->rider_id ? (int) $b->rider_id : null,
                'rider_name'       => $b->rider_id ? ($riderNames[$b->rider_id] ?? null) : null,
                // Cash bill jo rider ke khaate par hai (card bills khata par nahi hote).
                'rider_unsettled'  => (bool) ($b->rider_id && empty($b->rider_settlement_id) && $b->payment_method === 'cash' && $b->delivery_status !== 'returned'),
                'rider_open_count' => $b->rider_id ? ($riderOpen[$b->rider_id]['count'] ?? 0) : 0,
                'rider_open_amount'=> $b->rider_id ? ($riderOpen[$b->rider_id]['amount'] ?? 0) : 0,
            ];
        });

        return response()->json([
            'success' => true,
            'count'   => $data->count(),
            'bills'   => $data,
            'final_deliveries' => $finalData,
            // Current business day (00:00–05:59 counts in yesterday) — the
            // Pending Deliveries badge filters bills to THIS date client-side.
            'business_today' => $hasBizDate ? \App\Services\PosBusinessDay::current($companyId) : null,
        ]);
    }

    /**
     * TODAY'S BILLS — read-only list for the sale-screen Reprint modal (Alt+R).
     * Returns ALL of today's completed bills regardless of type: PRA-reported
     * finals, reporting-OFF finals (NULL status), offline/pending queue, failed,
     * and deliberate provisionals. Bypasses hide_archived so day-close-washed
     * bills stay reprintable until midnight. Cashiers allowed — print-only,
     * no mutations happen through this endpoint.
     */
    public function apiTodaysBills(Request $request)
    {
        $companyId = app('currentCompanyId');

        // Visibility MUST mirror receipt(): archived bills are listed ONLY when
        // invoice_mode='local' (post-finalize archive) — archived PRA bills stay
        // hidden, otherwise a listed row would 404 on the iframe print.
        $bills = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('status', 'completed')
            ->where(function ($q) {
                if (\Schema::hasColumn('pos_transactions', 'is_archived')) {
                    $q->where('is_archived', false)
                      ->orWhereNull('is_archived')
                      ->orWhere('invoice_mode', 'local');
                }
            })
            ->where('business_date', \App\Services\PosBusinessDay::current($companyId))
            ->orderBy('id', 'desc')
            ->limit(300)
            ->get(['id', 'invoice_number', 'pra_invoice_number', 'customer_name', 'total_amount', 'payment_method', 'order_type', 'invoice_mode', 'pra_status', 'created_at']);

        // Table name per bill (dine-in): batch lookup via restaurant_orders →
        // restaurant_tables so the Reprint list can show "Dine-in • Table 5".
        // One IN query — no N+1 on the 300-bill list.
        $tableByTx = [];
        if ($bills->isNotEmpty() && \Schema::hasTable('restaurant_orders') && \Schema::hasTable('restaurant_tables')) {
            // orderBy id: if duplicate orders ever point at one transaction
            // (data drift), pluck keeps the LAST row = the newest order.
            $tableByTx = \DB::table('restaurant_orders')
                ->join('restaurant_tables', 'restaurant_tables.id', '=', 'restaurant_orders.table_id')
                ->where('restaurant_orders.company_id', $companyId)
                ->whereIn('restaurant_orders.pos_transaction_id', $bills->pluck('id'))
                ->orderBy('restaurant_orders.id')
                ->pluck('restaurant_tables.table_number', 'restaurant_orders.pos_transaction_id')
                ->all();
        }

        $data = $bills->map(function ($b) use ($tableByTx) {
            // Badge resolution mirrors the Transactions-page tab split: the
            // ACTUAL PRA outcome decides, not invoice_mode alone.
            if (!empty($b->pra_invoice_number)) {
                $badge = 'pra';           // fiscal number issued = PRA-reported
            } elseif ($b->pra_status === 'local') {
                $badge = 'provisional';   // deliberate provisional (completed+local+local)
            } elseif (in_array($b->pra_status, ['offline', 'pending'], true)) {
                $badge = 'queue';         // waiting for PRA sync
            } elseif ($b->pra_status === 'failed') {
                $badge = 'failed';
            } else {
                $badge = 'local';         // reporting-OFF final (NULL status) etc.
            }
            return [
                'id'                 => $b->id,
                'invoice_number'     => $b->invoice_number,
                'pra_invoice_number' => $b->pra_invoice_number,
                'customer_name'      => $b->customer_name,
                'total_amount'       => (float) $b->total_amount,
                'payment_method'     => $b->payment_method,
                'order_type'         => $b->order_type,
                'table_number'       => $tableByTx[$b->id] ?? null,
                'badge'              => $badge,
                'created_time'       => $b->created_at?->format('h:i A'),
                'created_human'      => $b->created_at?->diffForHumans(),
            ];
        });

        return response()->json([
            'success' => true,
            'count'   => $data->count(),
            'bills'   => $data,
        ]);
    }

    /**
     * Delete a provisional bill (only pra_status='local' allowed via this API).
     * Submitted/pending bills MUST go through the regular delete route which
     * enforces stricter checks.
     */
    public function apiDeleteProvisional(Request $request, $id)
    {
        $companyId = app('currentCompanyId');

        // Cashiers may create + finalize provisional bills, but NEVER delete —
        // deletion is a company-admin decision (owner rule Jul 2026).
        $posUser = auth('pos')->user();
        if ($posUser && $posUser->isPosCashier()) {
            return response()->json([
                'success' => false,
                'message' => __('pos.no_permission_delete_bill_short'),
            ], 403);
        }

        $company = Company::find($companyId);
        $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->with('items')->first();

        if (!$tx) {
            return response()->json(['success' => false, 'message' => __('pos.bill_not_found')], 404);
        }
        if ($tx->pra_status !== 'local' || $tx->status !== 'completed' || $tx->invoice_mode !== 'local') {
            return response()->json([
                'success' => false,
                'message' => __('pos.only_provisional_deleted_endpoint'),
            ], 422);
        }

        // Provisional bills deduct stock at sale time just like finals, so the
        // F10 "Local" modal delete must restore it too — same rule as
        // deleteTransaction (only when tracking + restock-on-void are on).
        $restoreItems = ($company && $company->inventory_enabled && $company->pos_restock_on_void)
            ? $this->expandDealComponentsForStock($tx->items->map(fn ($i) => [
                'type' => $i->item_type ?? 'product',
                'item_id' => $i->item_id,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'deal_snapshot' => $i->deal_snapshot,
            ])->all())
            : [];

        DB::transaction(function () use ($tx, $companyId, $restoreItems) {
            if (!empty($restoreItems)) {
                PosInventoryController::restoreStockForInvoice(
                    $companyId, $restoreItems, $tx->id, $tx->invoice_number, auth('pos')->id(), 'pos_void'
                );
            }
            PosTransactionItem::where('transaction_id', $tx->id)->delete();
            PosPayment::where('transaction_id', $tx->id)->delete();
            $tx->delete();
        });

        return response()->json(['success' => true, 'message' => __('pos.provisional_bill_deleted_msg'), 'id' => $id]);
    }

    /**
     * Promote a provisional ('local') bill to a final PRA submission. Mirrors
     * the existing retryPra() flow but returns JSON for the inline modal.
     * Flips pra_status='local' → 'pending' + invoice_mode='pra' before submit.
     */
    /**
     * Shared provisional→final promote CORE — the single math/state path used by
     * BOTH apiPromoteProvisional (F10 Make Final) and the day-close auto-finalize
     * sweep ('finalize' policy, Aug 2026). Runs inside its own DB transaction:
     * race-safe lock + re-verify, month gate, re-tax for the (stored) payment
     * method, whole-rupee rounding / tax-inclusive snapshot math, serial split
     * (POS fiscal serial only when reporting ON), payment-record sync.
     * Throws RuntimeException: NOT_FOUND | NOT_PROVISIONAL:* | ARCHIVED_ADMIN_ONLY | MONTH_CLOSED.
     *
     * @return array{number:string,total:float}
     */
    private function promoteProvisionalCore(int $companyId, Company $company, int $id, ?string $method, bool $reportingOn): array
    {
        $newNumber = null;
        $newTotal  = null;
        DB::transaction(function () use ($companyId, $company, $id, $method, $reportingOn, &$newNumber, &$newTotal) {
                // Race-safe: lock the row and re-verify it is still a genuine provisional.
                // The F10 modal fires promote on Enter, so double-Enter is a real
                // double-promote path — the lock + re-check closes it.
                // hide_archived bypassed: archived local bills (local finals / day-close
                // archived) are promotable from the Local Invoices report too.
                $tx = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$tx) {
                    throw new \RuntimeException('NOT_FOUND');
                }
                if ($tx->pra_status !== 'local' || $tx->status !== 'completed' || $tx->invoice_mode !== 'local') {
                    throw new \RuntimeException('NOT_PROVISIONAL:' . $tx->status . '/' . $tx->pra_status);
                }

                // ARCHIVED local bills (deliberate LOCAL FINALS / day-close archived)
                // are promotable ONLY by admins — cashiers use F10 for LIVE provisionals
                // and must not resurrect a bill the owner finalized as local.
                if ($tx->is_archived && !(auth('pos')->user()?->isPosAdmin())) {
                    throw new \RuntimeException('ARCHIVED_ADMIN_ONLY');
                }

                // MONTH GATE (owner rule Jul 2026): only CURRENT-MONTH local bills may
                // be promoted to PRA. Once the month changes, previous-month locals are
                // closed — view/report only, never submitted late to PRA.
                if ($tx->created_at && $tx->created_at->lt(now()->startOfMonth())) {
                    throw new \RuntimeException('MONTH_CLOSED');
                }

                $payMethod = $method ?? $tx->payment_method;

                // Recompute tax from the STORED bill for the chosen method — mirrors
                // storeInvoice (stored subtotal, absolute discount, non-exempt lines).
                $items = $tx->items()->get();
                $lineSum = (float) $items->sum('subtotal');
                $subtotal = (float) $tx->subtotal;
                $discountAmount = (float) $tx->discount_amount;
                $afterDiscount = $subtotal - $discountAmount;
                $taxableSubtotal = (float) $items->reject(fn($it) => (bool) $it->is_tax_exempt)->sum('subtotal');
                $taxableAfterDiscount = $subtotal > 0 ? round($taxableSubtotal / $subtotal * $afterDiscount, 2) : 0;
                $exemptAfterDiscount = round($afterDiscount - $taxableAfterDiscount, 2);

                $taxRate = PosTaxRule::getRateForMethod($payMethod, $company);
                // Tax-Inclusive Pricing: promote branches on the bill's SNAPSHOT flag.
                // Stored item lines are menu (inclusive) prices, so re-deriving at a
                // DIFFERENT payment method's rate keeps the customer total invariant
                // (= menu sum − discount) — only the base/tax split moves. The header
                // subtotal column is re-derived too (it depends on the rate).
                $promoteTaxInclusive = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_inclusive')
                    && (bool) $tx->tax_inclusive;
                // Card-save (mode 3): promote CAN change the payment method — the
                // method rate is re-resolved above, but the MENU rate must come from
                // the bill's SNAPSHOT (never current company config, rates are mutable).
                $promoteMenuRate = $promoteTaxInclusive
                    && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_menu_rate')
                    && $tx->tax_menu_rate !== null
                    ? (float) $tx->tax_menu_rate
                    : null;
                if ($promoteTaxInclusive) {
                    $inc = \App\Services\PosTaxMath::inclusiveHeader($lineSum, $taxableSubtotal, $discountAmount, (float) $taxRate, $promoteMenuRate);
                    $taxAmount = $inc['tax_amount'];
                    $totalAmount = $inc['total_amount'];
                    $exemptAfterDiscount = $inc['exempt_amount'];
                    $headerSubtotal = $inc['subtotal_col'];
                    $shareBase = $lineSum;
                } else {
                    // Whole-rupee tax + total (Pakistan POS convention) — item lines stay 2dp.
                    $taxAmount = (float) round($taxableAfterDiscount * $taxRate / 100);
                    $totalAmount = (float) round($afterDiscount + $taxAmount);
                    $headerSubtotal = $subtotal;
                    $shareBase = $subtotal;
                }

                // Serial split (owner rule Jul 2026): a real POS fiscal serial replaces
                // the provisional L-NNN ONLY when the bill actually goes to PRA
                // (reporting ON). Reporting-OFF finalize keeps the L number — fiscal
                // serials are reserved for PRA-reported bills. generateInvoiceNumber's
                // lockForUpdate is effective inside this transaction.
                $newInvoiceNumber = $reportingOn
                    ? $this->generateInvoiceNumber($companyId)
                    : $tx->invoice_number;
                $newHash = hash('sha256', $companyId . '|' . $newInvoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

                $tx->update([
                    'invoice_number'    => $newInvoiceNumber,
                    'payment_method'    => $payMethod,
                    'subtotal'          => $headerSubtotal,
                    'tax_rate'          => $taxRate,
                    'tax_amount'        => $taxAmount,
                    'exempt_amount'     => $exemptAfterDiscount,
                    'total_amount'      => $totalAmount,
                    'cash_received'     => $payMethod === 'cash' ? $totalAmount : null,
                    'change_due'        => null,
                    'submission_hash'   => $newHash,
                    // Finalize out of 'local'. Reporting-OFF = 'pra' mode + NULL status
                    // (normal final, no submission). Reporting-ON = 'pending' for submit.
                    'invoice_mode'      => 'pra',
                    'pra_status'        => $reportingOn ? 'pending' : null,
                    'pra_response_code' => null,
                    // Un-archive: a promoted bill is a live PRA bill and must appear
                    // on the normal POS surfaces (archived local finals included).
                    'is_archived'       => false,
                    'archived_at'       => null,
                ]);

                // Re-tax each line for the new rate — the PRA payload reads per-item tax_rate.
                foreach ($items as $it) {
                    $itemTaxRate = (bool) $it->is_tax_exempt ? 0 : $taxRate;
                    $itemDiscountShare = $shareBase > 0 ? round($discountAmount * ((float) $it->subtotal / $shareBase), 2) : 0;
                    $itemTaxableAmount = (float) $it->subtotal - $itemDiscountShare;
                    $itemTaxAmount = $promoteTaxInclusive
                        ? \App\Services\PosTaxMath::inclusiveLineTax($itemTaxableAmount, (float) $itemTaxRate, $promoteMenuRate)
                        : round($itemTaxableAmount * $itemTaxRate / 100, 2);
                    $it->update([
                        'tax_rate'   => $itemTaxRate,
                        'tax_amount' => $itemTaxAmount,
                    ]);
                }

                // Sync the payment record — restaurant-origin provisionals may have none,
                // so updateOrCreate (a plain update would silently no-op).
                PosPayment::updateOrCreate(
                    ['transaction_id' => $tx->id],
                    ['payment_method' => $payMethod, 'amount' => $totalAmount]
                );

                $newNumber = $newInvoiceNumber;
                $newTotal  = $totalAmount;
        });

        return ['number' => $newNumber, 'total' => (float) $newTotal];
    }

    public function apiPromoteProvisional(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (!$company) {
            return response()->json(['success' => false, 'message' => __('pos.company_not_found')], 404);
        }

        // ── LOCAL FINAL (owner request Jul 2026): finalize WITHOUT sending to PRA ──
        // The bill keeps its local invoice number, amounts and payment method exactly
        // as saved, and is archived immediately — it leaves the F10 provisional list
        // but stays visible in the Local Bills Portal (which reads archived bills).
        // pra_status stays 'local' + invoice_mode stays 'local' (deliberate local bill,
        // consistent with the Reporting-OFF Finals Invariant).
        if (!$request->boolean('send_to_pra', true)) {
            $tx = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->where('status', 'completed')
                ->where('invoice_mode', 'local')
                ->where('pra_status', 'local')
                ->first();
            if (!$tx) {
                return response()->json(['success' => false, 'message' => __('pos.bill_not_found')], 404);
            }
            $tx->update(['is_archived' => true, 'archived_at' => now()]);
            return response()->json([
                'success'        => true,
                'submitted'      => false,
                'local_final'    => true,
                'invoice_number' => $tx->invoice_number,
                'total_amount'   => (float) $tx->total_amount,
                'message'        => __('pos.bill_finalized_local_not_pra', ['number' => $tx->invoice_number, 'amount' => number_format((float) $tx->total_amount)]),
                'id'             => $tx->id,
            ]);
        }

        // Cashier picks the settlement method AT promote time — cash vs card carry
        // different PRA tax rates (e.g. 16% vs 8%), so the bill is RE-TAXED for the
        // chosen method. Falls back to the stored method when none is supplied.
        $method = $request->input('payment_method');
        if ($method === 'card') {
            $method = 'debit_card';
        }
        if (!in_array($method, ['cash', 'debit_card', 'credit_card', 'qr_payment'], true)) {
            $method = null; // resolve from the stored value inside the transaction
        }

        // Promoting a provisional to a FINAL bill consumes monthly quota — same
        // gate as storeInvoice finals (paid-plan package limits, Jul 2026).
        $quota = \App\Services\PlanLimitService::canCreatePosBill($companyId);
        if (!($quota['allowed'] ?? true)) {
            return response()->json(['success' => false, 'message' => $quota['reason']], 403);
        }

        // Per-cashier toggle (owner rule Jul 2026): the promoting user's own switch decides.
        $reportingOn = (bool) auth('pos')->user()?->praReportingEnabled($company);
        $newNumber = null;
        $newTotal  = null;

        try {
            $res = $this->promoteProvisionalCore($companyId, $company, (int) $id, $method, $reportingOn);
            $newNumber = $res['number'];
            $newTotal  = $res['total'];
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if ($msg === 'NOT_FOUND') {
                return response()->json(['success' => false, 'message' => __('pos.bill_not_found')], 404);
            }
            if ($msg === 'MONTH_CLOSED') {
                return response()->json([
                    'success' => false,
                    'message' => __('pos.only_current_month_local_pra_report_only'),
                ], 422);
            }
            if ($msg === 'ARCHIVED_ADMIN_ONLY') {
                return response()->json([
                    'success' => false,
                    'message' => __('pos.bill_local_final_admin_only'),
                ], 403);
            }
            if (str_starts_with($msg, 'NOT_PROVISIONAL:')) {
                return response()->json([
                    'success' => false,
                    'message' => __('pos.only_completed_provisional_promoted', ['status' => substr($msg, 16)]),
                ], 422);
            }
            return response()->json(['success' => false, 'message' => __('pos.promote_failed', ['error' => $msg])], 500);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => __('pos.promote_failed', ['error' => $e->getMessage()])], 500);
        }

        // ── Post-commit: PRA submission happens STRICTLY outside the transaction ──
        if (!$reportingOn) {
            return response()->json([
                'success'        => true,
                'submitted'      => false,
                'invoice_number' => $newNumber,
                'total_amount'   => $newTotal,
                'message'        => __('pos.bill_now_final_pra_off_amount', ['number' => $newNumber, 'amount' => number_format($newTotal)]),
                'id'             => $id,
            ]);
        }

        $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->first();

        // Agent Sync mode: just leave it queued — desktop agent picks it up within 10s.
        if ($company->agentHandlesPra()) {
            return response()->json([
                'success'        => true,
                'queued'         => true,
                'invoice_number' => $newNumber,
                'total_amount'   => $newTotal,
                'message'        => __('pos.bill_requeued_agent', ['number' => $newNumber]),
                'id'             => $id,
            ]);
        }

        try {
            $praService = new PraIntegrationService($company);
            $result = $praService->sendInvoice($tx);
            $tx->refresh();

            if (!empty($result['success'])) {
                return response()->json([
                    'success'        => true,
                    'submitted'      => true,
                    'invoice_number' => $newNumber,
                    'total_amount'   => $newTotal,
                    'message'        => __('pos.pra_submission_successful_num', ['number' => $tx->pra_invoice_number ?? 'N/A']),
                    'pra_number'     => $tx->pra_invoice_number,
                    'id'             => $id,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => __('pos.pra_submission_failed', ['error' => $result['message'] ?? __('pos.unknown_error')]),
                'id'      => $id,
            ], 502);
        } catch (\Exception $e) {
            $tx->update(['pra_status' => 'offline']);
            return response()->json([
                'success' => false,
                'offline' => true,
                'message' => __('pos.pra_connection_failed_short'),
                'id'      => $id,
            ], 503);
        }
    }

    /**
     * List failed/offline PRA bills for the F11 header shortcut modal.
     * Returns bills with pra_status IN ('failed','offline','pending') that
     * have NOT yet received a pra_invoice_number (i.e. need cashier attention).
     */
    public function apiFailedBills(Request $request)
    {
        $companyId = app('currentCompanyId');
        $bills = PosTransaction::where('company_id', $companyId)
            ->whereIn('pra_status', ['failed', 'offline', 'pending'])
            ->whereNull('pra_invoice_number')
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get(['id', 'invoice_number', 'customer_name', 'total_amount', 'pra_status', 'pra_response_code', 'created_at']);

        $data = $bills->map(function ($b) {
            return [
                'id'             => $b->id,
                'invoice_number' => $b->invoice_number,
                'customer_name'  => $b->customer_name,
                'total_amount'   => (float) $b->total_amount,
                'pra_status'     => $b->pra_status,
                'error_code'     => $b->pra_response_code,
                'items_count'    => PosTransactionItem::where('transaction_id', $b->id)->count(),
                'created_human'  => $b->created_at?->diffForHumans(),
                'created_at'     => $b->created_at?->toDateTimeString(),
            ];
        });

        return response()->json([
            'success' => true,
            'count'   => $data->count(),
            'bills'   => $data,
        ]);
    }

    /**
     * Retry a failed/offline PRA submission via JSON (F11 modal action).
     * Mirrors retryPra() but returns JSON instead of back()->with().
     */
    public function apiRetryFailed(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (!$company || !auth('pos')->user()?->praReportingEnabled($company)) {
            return response()->json([
                'success' => false,
                'message' => __('pos.pra_reporting_disabled_enable'),
            ], 422);
        }

        // ATOMIC CLAIM — race-safe. Conditional UPDATE returns affected-row
        // count; if 0, another concurrent request already claimed/submitted
        // this bill (double-click, two tabs, queue worker, etc.).
        $claimed = PosTransaction::where('company_id', $companyId)
            ->where('id', $id)
            ->whereNull('pra_invoice_number')
            ->whereIn('pra_status', ['pending', 'failed', 'offline'])
            ->update(['pra_status' => 'pending', 'pra_response_code' => null]);

        if ($claimed === 0) {
            // Either bill doesn't exist, was already submitted, or another
            // request claimed it. Re-fetch to give the cashier the right reason.
            $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->first();
            if (!$tx) {
                return response()->json(['success' => false, 'message' => __('pos.bill_not_found')], 404);
            }
            if ($tx->pra_invoice_number) {
                return response()->json([
                    'success' => false,
                    'message' => __('pos.already_submitted_pra_num', ['number' => $tx->pra_invoice_number]),
                ], 422);
            }
            return response()->json([
                'success' => false,
                'message' => __('pos.cannot_retry_status_changed', ['status' => $tx->pra_status]),
            ], 409);
        }

        $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->first();

        if ($company->agentHandlesPra()) {
            return response()->json([
                'success' => true,
                'queued'  => true,
                'message' => __('pos.requeued_desktop_agent'),
                'id'      => $id,
            ]);
        }

        try {
            $praService = new PraIntegrationService($company);
            $result = $praService->sendInvoice($tx);
            $tx->refresh();

            if (!empty($result['success'])) {
                return response()->json([
                    'success'    => true,
                    'submitted'  => true,
                    'message'    => __('pos.pra_submission_successful_num_short', ['number' => $tx->pra_invoice_number ?? 'N/A']),
                    'pra_number' => $tx->pra_invoice_number,
                    'id'         => $id,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => __('pos.pra_submission_failed', ['error' => $result['message'] ?? __('pos.unknown_error')]),
                'id'      => $id,
            ], 502);
        } catch (\Exception $e) {
            $tx->update(['pra_status' => 'offline']);
            return response()->json([
                'success' => false,
                'offline' => true,
                'message' => __('pos.pra_connection_failed_short'),
                'id'      => $id,
            ], 503);
        }
    }

    private function verifyPinSession(): bool
    {
        return session('confidential_pin_verified', false) === true;
    }

    private function clearPinSession(): void
    {
        session()->forget(['confidential_pin_verified', 'confidential_pin_verified_at']);
    }

    private function requirePinForLocalTab(string $tab, Company $company)
    {
        if ($tab !== 'local') {
            $this->clearPinSession();
            return null;
        }

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';

        if ($isCashier) {
            if (empty($company->confidential_pin)) {
                return redirect()->back()->with('error', __('pos.local_data_access_restricted'));
            }
            if (!$this->verifyPinSession()) {
                return redirect()->back()->with('error', __('pos.pin_required_local_data'));
            }
        } elseif (!empty($company->confidential_pin) && !$this->verifyPinSession()) {
            return redirect()->back()->with('error', __('pos.pin_required_local_data'));
        }

        $this->clearPinSession();

        return null;
    }

    public function transactions(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = auth('pos')->user();
        // Same isolated tab split as Sales/Tax Reports (owner rule Jul 2026):
        //   PRA tab   → bills actually in the PRA pipeline (pra_status NOT NULL or fiscal number)
        //   Local tab → ADMIN-ONLY: L-series bills + reporting-OFF finals (no PRA fiscal).
        // Cashiers are always forced to PRA server-side.
        $tab = ($request->get('tab') === 'local' && $user?->isPosAdmin()) ? 'local' : 'pra';

        $query = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->with('creator');

        $this->applyReportFilters($query, $tab);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $like = \App\Helpers\DbCompat::like();
                $q->where('invoice_number', $like, "%{$search}%")
                    ->orWhere('customer_name', $like, "%{$search}%");
            });
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate(20);

        $hasPinSet = !empty($company->confidential_pin);
        $localCount = 0;

        return view('pos.transactions', compact('transactions', 'tab', 'hasPinSet', 'localCount', 'user'));
    }

    public function transactionShow($id)
    {
        $companyId = app('currentCompanyId');
        // withoutGlobalScope: archived LOCAL bills are listed in the admin-only
        // Local tab (Transactions + Reports) — their detail page must open.
        // Bypass is limited to LOCAL-mode bills so archived PRA bills stay hidden
        // (same pattern as receipt()).
        $transaction = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where(function ($q) {
                if (\Schema::hasColumn('pos_transactions', 'is_archived')) {
                    $q->where('is_archived', false)
                      ->orWhereNull('is_archived')
                      ->orWhere('invoice_mode', 'local');
                }
            })
            ->with(['items', 'payments', 'praLogs', 'creator', 'terminal'])
            ->findOrFail($id);

        return view('pos.transaction-show', compact('transaction'));
    }

    public function receipt($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // withoutGlobalScope: a bill finalized as LOCAL (no PRA) is archived on the
        // spot — its receipt must still render for the post-finalize popup/print.
        // Bypass is limited to LOCAL-mode bills so archived PRA bills stay hidden
        // (preserves the "nothing sees archived bills" invariant for PRA data).
        $transaction = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where(function ($q) {
                if (\Schema::hasColumn('pos_transactions', 'is_archived')) {
                    $q->where('is_archived', false)
                      ->orWhereNull('is_archived')
                      ->orWhere('invoice_mode', 'local');
                }
            })
            ->with(['items', 'payments', 'creator', 'terminal'])
            ->findOrFail($id);

        $printerSize = $company->receipt_printer_size ?? '80mm';
        $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';

        return view($receiptView, compact('transaction', 'company'));
    }

    /**
     * Estimate the page height (in points) for a thermal receipt PDF.
     *
     * DomPDF ignores the receipt CSS `@page { size: 80mm auto }`, so we MUST pass an
     * explicit height to setPaper(). The old hard-coded 1200pt truncated any receipt
     * taller than that — the cashier reported the downloaded slip "poori nahi hoti"
     * (cut off). We size the page to its content and over-estimate slightly: trailing
     * whitespace on a thermal roll is harmless, a clipped receipt is not.
     */
    /**
     * Render an A4 report PDF via mPDF for 'ur' locale (shaped Urdu, RTL) or
     * DomPDF for en/rur.  Always passes $pdfUrdu bool into view data so
     * templates can gate RTL CSS and font overrides.
     *
     * On mPDF failure the method logs a warning and falls back to DomPDF Roman
     * Urdu (applyPdfSafeLocale) — no 500 is ever returned.
     */
    private function renderReportPdf(
        string $view,
        array $data,
        string $filename,
        string $orientation = 'portrait'
    ): \Illuminate\Http\Response {
        $isUrdu = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT;
        $data['pdfUrdu'] = $isUrdu;

        if ($isUrdu) {
            try {
                return \App\Support\MpdfRenderer::render(
                    $view, $data, 'a4-report', $filename, false, $orientation
                );
            } catch (\Throwable $e) {
                \Log::warning("mPDF report render failed [{$filename}]: " . $e->getMessage());
            }
        }

        // DomPDF path — drop 'ur' → 'rur' so DomPDF never receives unshaped glyphs.
        \App\Support\PosLocale::applyPdfSafeLocale();
        $data['pdfUrdu'] = false; // ensure template sees false on this path

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $data)
            ->setPaper('a4', $orientation);

        return $pdf->download($filename);
    }

    private function estimateReceiptHeightPt($transaction, $company, string $printerSize): float
    {
        $charsPerLine = $printerSize === '58mm' ? 12 : 18; // monospace chars fitting the Item column
        $perLine = 22.0;                                    // pt consumed per (wrapped) item line

        $itemLines = 0;
        foreach ($transaction->items as $it) {
            $len = mb_strlen((string) ($it->item_name ?? ''));
            $itemLines += max(1, (int) ceil($len / max(1, $charsPerLine)));
        }

        $height  = 520.0;                                          // header + info + totals + footer chrome (+ header-field wrap headroom + PAYMENT banner, Jul 2026)
        $height += ($company && $company->logo_path) ? 70.0 : 0.0; // logo block
        $height += $itemLines * $perLine;                          // item rows
        $height += ($transaction->discount_amount > 0) ? 26.0 : 0.0;
        // Notes wrap to several lines on a narrow thermal roll — scale by length, never assume one line.
        if (!empty($transaction->notes)) {
            $noteCharsPerLine = $printerSize === '58mm' ? 28 : 40;
            $noteLines = max(1, (int) ceil(mb_strlen((string) $transaction->notes) / $noteCharsPerLine));
            $height += 20.0 + ($noteLines * 14.0);
        }
        $height += 250.0;                                          // PRA/provisional badge + 100px QR + caption
        $height += 80.0;                                           // safety tail so nothing clips

        return max(640.0, $height);
    }

    public function downloadInvoicePdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with(['items', 'terminal', 'creator'])
            ->findOrFail($id);

        // Use the same thermal receipt template as the screen-print path so the
        // downloadable PDF matches what the cashier sees / prints. 80mm = 226.77pt
        // wide; height set to A4-ish so DomPDF auto-paginates if a receipt grows
        // unusually long. 58mm (164.41pt) for narrow thermal printers.
        $printerSize = $company->receipt_printer_size ?? '80mm';
        $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';
        $paperWidthPt = $printerSize === '58mm' ? 164.41 : 226.77;

        // PDF Download Paper (customer video Jul 2026): shops printing the downloaded
        // PDF on a regular office printer got a right-shifted, clipped print — PDF
        // viewers CENTER the narrow thermal page on the driver's A4 canvas. Opt-in
        // 'a4' mode makes the PDF a real A4 page with the receipt strip top-left.
        $pdfPaper = ($company->invoice_display_prefs['pos_style']['pdf_paper'] ?? 'thermal') === 'a4' ? 'a4' : 'thermal';

        // Task 260: locale 'ur' → mPDF (Arabic OTL shaping). en/rur → DomPDF unchanged.
        // If mPDF is unavailable or throws, log + fall back to DomPDF Roman Urdu (never 500).
        if (app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT) {
            try {
                $viewData = ['transaction' => $transaction, 'company' => $company, 'pdfMode' => true, 'pdfPaper' => $pdfPaper];
                if ($pdfPaper === 'a4') {
                    $paper = 'a4';
                } else {
                    $heightPt = $this->estimateReceiptHeightPt($transaction, $company, $printerSize);
                    $paper = [$paperWidthPt / 2.8346, $heightPt / 2.8346]; // pt → mm
                }
                return \App\Support\MpdfRenderer::render($receiptView, $viewData, $paper, "Invoice-{$transaction->invoice_number}.pdf", false);
            } catch (\Throwable $e) {
                \Log::warning('mPDF render failed for downloadInvoicePdf, falling back to DomPDF Roman Urdu: ' . $e->getMessage());
            }
        }

        \App\Support\PosLocale::applyPdfSafeLocale(); // DomPDF can't shape Urdu script — PDF falls back to Roman Urdu

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($receiptView, ['transaction' => $transaction, 'company' => $company, 'pdfMode' => true, 'pdfPaper' => $pdfPaper])
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true);
        if ($pdfPaper === 'a4') {
            $pdf->setPaper('a4', 'portrait'); // auto-paginates if a receipt outgrows one page
        } else {
            $pdf->setPaper([0, 0, $paperWidthPt, $this->estimateReceiptHeightPt($transaction, $company, $printerSize)], 'portrait');
        }

        return $pdf->download("Invoice-{$transaction->invoice_number}.pdf");
    }

    public function generateShareLink($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if (!$transaction->share_token) {
            $transaction->update([
                'share_token' => bin2hex(random_bytes(32)),
                'share_token_created_at' => now(),
            ]);
        }

        $shareUrl = url("/pos/invoice/share/{$transaction->share_token}");

        return response()->json(['url' => $shareUrl, 'token' => $transaction->share_token]);
    }

    public function publicInvoicePdf($token)
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            abort(404);
        }

        $transaction = PosTransaction::where('share_token', $token)
            ->with(['items', 'terminal', 'creator'])
            ->firstOrFail();

        if ($transaction->share_token_created_at && $transaction->share_token_created_at < now()->subDays(30)) {
            abort(410, 'This share link has expired.');
        }

        $company = Company::find($transaction->company_id);
        if (!$company) {
            abort(404);
        }

        // Match the thermal receipt format used everywhere else (screen, print,
        // download). Share-link recipients (WhatsApp / Email) get the exact same
        // receipt their cashier handed over.
        $printerSize = $company->receipt_printer_size ?? '80mm';
        $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';
        $paperWidthPt = $printerSize === '58mm' ? 164.41 : 226.77;

        // Same PDF Download Paper handling as downloadInvoicePdf — share-link
        // recipients print on regular printers even more often than cashiers.
        $pdfPaper = ($company->invoice_display_prefs['pos_style']['pdf_paper'] ?? 'thermal') === 'a4' ? 'a4' : 'thermal';

        // Task 260: locale 'ur' → mPDF (Arabic OTL shaping). en/rur → DomPDF unchanged.
        if (app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT) {
            try {
                $viewData = ['transaction' => $transaction, 'company' => $company, 'pdfMode' => true, 'pdfPaper' => $pdfPaper];
                if ($pdfPaper === 'a4') {
                    $paper = 'a4';
                } else {
                    $heightPt = $this->estimateReceiptHeightPt($transaction, $company, $printerSize);
                    $paper = [$paperWidthPt / 2.8346, $heightPt / 2.8346];
                }
                return \App\Support\MpdfRenderer::render($receiptView, $viewData, $paper, "Invoice-{$transaction->invoice_number}.pdf", true);
            } catch (\Throwable $e) {
                \Log::warning('mPDF render failed for publicInvoicePdf, falling back to DomPDF Roman Urdu: ' . $e->getMessage());
            }
        }

        \App\Support\PosLocale::applyPdfSafeLocale(); // DomPDF can't shape Urdu script — PDF falls back to Roman Urdu

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($receiptView, ['transaction' => $transaction, 'company' => $company, 'pdfMode' => true, 'pdfPaper' => $pdfPaper])
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true);
        if ($pdfPaper === 'a4') {
            $pdf->setPaper('a4', 'portrait');
        } else {
            $pdf->setPaper([0, 0, $paperWidthPt, $this->estimateReceiptHeightPt($transaction, $company, $printerSize)], 'portrait');
        }

        return $pdf->stream("Invoice-{$transaction->invoice_number}.pdf");
    }

    private function applyReportFilters($query, $tab, $cashierFilter = null)
    {
        // Two FULLY ISOLATED report sets (owner rule Jul 2026) — split by whether the
        // bill was actually REPORTED to PRA (fiscal), not just by invoice_mode:
        //   tab='pra'   → bills in the PRA pipeline: pra_status NOT NULL (pending /
        //                 completed / failed / offline) OR a PRA fiscal number exists.
        //   tab='local' → bills PRA never saw: L-series (invoice_mode='local') PLUS
        //                 reporting-OFF finals (mode pra/NULL + pra_status NULL + no
        //                 fiscal number — "jis pe PRA fiscal nahi aya wo local hai").
        //                 INCLUDING archived ones, so hide_archived is bypassed.
        //                 Admin-only (callers force 'pra' for cashiers).
        if ($tab === 'local') {
            $query->withoutGlobalScope('hide_archived')->where(function ($sub) {
                $sub->where('invoice_mode', 'local')
                    ->orWhere(function ($s) {
                        $s->whereNull('pra_status')->whereNull('pra_invoice_number');
                    });
            });
        } else {
            $query->where(function ($sub) {
                $sub->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })->where(function ($sub) {
                $sub->whereNotNull('pra_status')->orWhereNotNull('pra_invoice_number');
            });
        }

        if ($cashierFilter && $cashierFilter !== 'all') {
            $query->where('created_by', $cashierFilter);
        }

        return $query;
    }

    /**
     * Tax-rate options for the report filter dropdown — DYNAMIC, never hardcoded:
     *   1. every distinct item-level rate that actually exists in THIS tab's data
     *      (historical bills keep old rates filterable), and
     *   2. the currently CONFIGURED effective rates (global pos_tax_rules + the
     *      company's overrides), so an updated rate appears immediately.
     * Computed PER TAB so PRA and Local rate sets stay fully isolated.
     */
    private function availableTaxRates(int $companyId, string $tab, ?Company $company): array
    {
        $txQuery = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->select('id');
        $this->applyReportFilters($txQuery, $tab);

        $dataRates = \App\Models\PosTransactionItem::whereIn('transaction_id', $txQuery)
            ->where('is_tax_exempt', false)
            ->where('tax_rate', '>', 0)
            ->distinct()
            ->pluck('tax_rate');

        $configuredRates = PosTaxRule::effectiveRules($company)->pluck('tax_rate');

        return $dataRates->concat($configuredRates)
            ->map(fn ($r) => round((float) $r, 2))
            ->filter(fn ($r) => $r > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function reports(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        // Local Invoices tab is ADMIN-ONLY — cashiers are always forced to PRA.
        $tab = ($request->get('tab') === 'local' && $user?->isPosAdmin()) ? 'local' : 'pra';
        $cashierFilter = $request->get('cashier', 'all');

        if ($isCashier && $cashierFilter !== 'all' && $cashierFilter != $user->id) {
            $cashierFilter = $user->id;
        }

        $teamMembers = User::where('company_id', $companyId)
            ->whereNotNull('pos_role')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'pos_role']);

        $modeFilter = function ($q) use ($tab, $cashierFilter) {
            $this->applyReportFilters($q, $tab, $cashierFilter);
        };

        // Sales reports group by BUSINESS day (owner rule 26 Jul 2026): an
        // after-midnight bill counts toward the previous day's business.
        // Tax reports (buildTaxReportQuery) stay on created_at — PRA legal truth.
        $dailySales = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('business_date', '>=', now()->subDays(30)->toDateString())
            ->tap($modeFilter)
            ->selectRaw("business_date as date, COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue")
            ->groupBy('business_date')
            ->orderBy('date', 'desc')
            ->get();

        $paymentSummary = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('business_date', '>=', now()->startOfMonth()->toDateString())
            ->tap($modeFilter)
            ->selectRaw("payment_method, COUNT(*) as count, COALESCE(SUM(total_amount),0) as total, COALESCE(SUM(tax_amount),0) as tax")
            ->groupBy('payment_method')
            ->get();

        $topItems = PosTransactionItem::whereHas('transaction', function ($q) use ($companyId, $tab, $cashierFilter) {
            $q->where('company_id', $companyId)->where('status', 'completed')->where('business_date', '>=', now()->startOfMonth()->toDateString());
            $this->applyReportFilters($q, $tab, $cashierFilter);
        })
            ->selectRaw("item_name, SUM(quantity) as total_qty, SUM(subtotal) as total_revenue")
            ->groupBy('item_name')
            ->orderByDesc('total_revenue')
            ->take(10)
            ->get();

        $monthlyTrend = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('business_date', '>=', now()->subMonths(6)->startOfMonth()->toDateString())
            ->tap($modeFilter)
            ->selectRaw(\App\Helpers\DbCompat::dateFormat('business_date', 'YYYY-MM') . " as month, COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue")
            ->groupByRaw(\App\Helpers\DbCompat::dateFormat('business_date', 'YYYY-MM'))
            ->orderBy('month')
            ->get();

        $hasPinSet = !empty($company->confidential_pin);
        $localCount = 0;
        $selectedCashier = $cashierFilter;

        // Local tab: list the individual local invoices so the admin can promote
        // any CURRENT-MONTH bill to PRA (previous months are closed — view only).
        $localBills = null;
        $monthStart = now()->startOfMonth();
        if ($tab === 'local') {
            $localBills = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('status', 'completed')
                // Same non-reported set as applyReportFilters: L-series bills PLUS
                // reporting-OFF finals (no PRA fiscal ever).
                ->where(function ($sub) {
                    $sub->where('invoice_mode', 'local')
                        ->orWhere(function ($s) {
                            $s->whereNull('pra_status')->whereNull('pra_invoice_number');
                        });
                })
                ->when($cashierFilter && $cashierFilter !== 'all', fn ($q) => $q->where('created_by', $cashierFilter))
                ->with('creator')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString();
        }

        // ── Range analytics (owner request Jul 2026): date-window deep dive ──
        [$rangeFrom, $rangeTo] = $this->resolveReportRange($request);
        $rangeAnalytics = $this->buildReportRangeAnalytics($companyId, $rangeFrom, $rangeTo, $tab, $cashierFilter, $company, $user);

        return view('pos.reports', compact('dailySales', 'paymentSummary', 'topItems', 'monthlyTrend', 'tab', 'hasPinSet', 'localCount', 'user', 'teamMembers', 'isCashier', 'selectedCashier', 'localBills', 'monthStart', 'rangeAnalytics'));
    }

    /**
     * A4 PDF export of the range analytics (owner request Jul 2026) — same data
     * set as the on-page analytics block (tab + cashier + date range aware).
     */
    public function reportsAnalyticsPdf(Request $request)
    {
        if ($r = $this->planGate('analytics_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        $tab = ($request->get('tab') === 'local' && $user?->isPosAdmin()) ? 'local' : 'pra';
        $cashierFilter = $request->get('cashier', 'all');
        if ($isCashier && $cashierFilter !== 'all' && $cashierFilter != $user->id) {
            $cashierFilter = $user->id;
        }

        [$rangeFrom, $rangeTo] = $this->resolveReportRange($request);
        $analytics = $this->buildReportRangeAnalytics($companyId, $rangeFrom, $rangeTo, $tab, $cashierFilter, $company, $user);

        return $this->renderReportPdf(
            'pos.reports-analytics-pdf',
            compact('company', 'analytics', 'tab'),
            'Sales-Analytics-' . $analytics->from . '-to-' . $analytics->to . '.pdf'
        );
    }

    public function exportReportCsv(Request $request)
    {
        if ($r = $this->planGate('reports_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        // Local export is ADMIN-ONLY — cashiers always export PRA data.
        $tab = ($request->get('tab') === 'local' && $user?->isPosAdmin()) ? 'local' : 'pra';
        $cashierFilter = $request->get('cashier', 'all');

        if ($isCashier && $cashierFilter !== 'all' && $cashierFilter != $user->id) {
            $cashierFilter = $user->id;
        }

        // Custom date range (analytics block) wins; default stays last 30 days.
        $hasRange = $request->filled('from') || $request->filled('to');
        [$rangeFrom, $rangeTo] = $this->resolveReportRange($request);

        $transactions = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->when($hasRange,
                fn ($q) => $q->whereBetween('business_date', [$rangeFrom->toDateString(), $rangeTo->toDateString()]),
                fn ($q) => $q->where('business_date', '>=', now()->subDays(30)->toDateString()))
            ->tap(fn ($q) => $this->applyReportFilters($q, $tab))
            ->when($cashierFilter && $cashierFilter !== 'all', fn($q) => $q->where('created_by', $cashierFilter))
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->get();

        $filterLabel = $cashierFilter === 'all' ? 'All Staff' : ($transactions->first()?->creator?->name ?? 'Staff #' . $cashierFilter);
        $filename = 'POS_Report_' . ($cashierFilter === 'all' ? 'AllStaff' : 'Staff') . '_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        // Period label so the export is self-describing (range vs default window).
        $periodLabel = $hasRange
            ? 'Period: ' . $rangeFrom->format('d M Y') . ' — ' . $rangeTo->format('d M Y')
            : 'Period: Last 30 days';

        $callback = function () use ($transactions, $filterLabel, $tab, $company, $periodLabel) {
            $file = fopen('php://output', 'w');
            // CSV numerics must stay Excel-parseable — NO thousands separators.
            $n = fn ($v) => number_format((float) $v, 2, '.', '');
            fputcsv($file, ['POS Sales Report — ' . $company->name]);
            fputcsv($file, ['Mode: ' . strtoupper($tab), 'Filter: ' . $filterLabel, $periodLabel, 'Generated: ' . now()->format('d M Y H:i')]);
            fputcsv($file, []);
            fputcsv($file, ['Date', 'Invoice #', 'Customer', 'Payment Method', 'Subtotal', 'Tax', 'Total', 'Staff']);

            foreach ($transactions as $t) {
                fputcsv($file, [
                    $t->created_at->format('d M Y H:i'),
                    $t->invoice_number,
                    $t->customer_name ?: 'Walk-in',
                    ucwords(str_replace('_', ' ', $t->payment_method)),
                    $n($t->subtotal),
                    $n($t->tax_amount),
                    $n($t->total_amount),
                    $t->creator?->name ?? '-',
                ]);
            }

            $totalSubtotal = $transactions->sum('subtotal');
            $totalRevenue = $transactions->sum('total_amount');
            $totalTax = $transactions->sum('tax_amount');
            fputcsv($file, []);
            fputcsv($file, ['', '', '', 'TOTALS', $n($totalSubtotal), $n($totalTax), $n($totalRevenue), '']);
            fputcsv($file, ['Total Transactions: ' . $transactions->count()]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function buildTaxReportQuery(Request $request, $tab = 'pra', $skipTaxRateFilter = false)
    {
        $companyId = app('currentCompanyId');
        $query = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->with('terminal');

        // Isolated tab sets: PRA (default) vs Local (admin-only, callers gate).
        $this->applyReportFilters($query, $tab);

        if (!$skipTaxRateFilter && $request->filled('tax_rate')) {
            if ($request->tax_rate === 'exempt') {
                $query->where('exempt_amount', '>', 0);
            } else {
                $rate = (float) $request->tax_rate;
                $query->where('tax_rate', $rate);
            }
        }

        if ($skipTaxRateFilter && $request->filled('tax_rate')) {
            if ($request->tax_rate === 'exempt') {
                $query->whereHas('items', function ($q) {
                    $q->where('is_tax_exempt', true);
                });
            } else {
                $rate = (float) $request->tax_rate;
                $query->whereHas('items', function ($q) use ($rate) {
                    $q->where('is_tax_exempt', false)->where('tax_rate', $rate);
                });
            }
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('customer')) {
            $query->where('customer_name', \App\Helpers\DbCompat::like(), '%' . $request->customer . '%');
        }

        $hasDateRange = $request->filled('date_from') || $request->filled('date_to');

        if ($hasDateRange) {
            if ($request->filled('date_from')) {
                $fromDate = \Carbon\Carbon::parse($request->date_from)->startOfDay();
                $query->where('created_at', '>=', $fromDate);
            }
            if ($request->filled('date_to')) {
                $toDate = \Carbon\Carbon::parse($request->date_to)->endOfDay();
                $query->where('created_at', '<=', $toDate);
            }
        } elseif ($request->filled('period')) {
            switch ($request->period) {
                case 'today':
                    $query->where('created_at', '>=', now()->startOfDay());
                    break;
                case 'yesterday':
                    $query->where('created_at', '>=', now()->subDay()->startOfDay())
                          ->where('created_at', '<=', now()->subDay()->endOfDay());
                    break;
                case 'weekly':
                    $query->where('created_at', '>=', now()->startOfWeek());
                    break;
                case 'monthly':
                    $query->where('created_at', '>=', now()->startOfMonth());
                    break;
                case 'last_month':
                    $query->where('created_at', '>=', now()->subMonth()->startOfMonth())
                          ->where('created_at', '<=', now()->subMonth()->endOfMonth());
                    break;
            }
        }

        $query->orderBy('created_at', 'desc');
        return $query;
    }

    private function getReportDateLabel(Request $request): string
    {
        $hasDateRange = $request->filled('date_from') || $request->filled('date_to');

        if ($hasDateRange) {
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $from = \Carbon\Carbon::parse($request->date_from)->format('d M Y');
                $to = \Carbon\Carbon::parse($request->date_to)->format('d M Y');
                return $from . ' to ' . $to;
            }
            if ($request->filled('date_from')) {
                return \Carbon\Carbon::parse($request->date_from)->format('d M Y') . ' to Present';
            }
            if ($request->filled('date_to')) {
                return 'Up to ' . \Carbon\Carbon::parse($request->date_to)->format('d M Y');
            }
        }

        if ($request->filled('period')) {
            return match ($request->period) {
                'today' => 'Today (' . now()->format('d M Y') . ')',
                'yesterday' => 'Yesterday (' . now()->subDay()->format('d M Y') . ')',
                'weekly' => 'This Week (' . now()->startOfWeek()->format('d M') . ' - ' . now()->endOfWeek()->format('d M Y') . ')',
                'monthly' => 'This Month (' . now()->format('M Y') . ')',
                'last_month' => 'Last Month (' . now()->subMonth()->format('M Y') . ')',
                default => 'All Time',
            };
        }
        return 'All Time';
    }

    /**
     * Tax-Inclusive Pricing (Menu-Rate-Final): item `subtotal` stores the MENU
     * (tax-in) line amount on inclusive bills, while exclusive bills store the
     * ex-tax amount. Reports must always aggregate the EX-TAX base so the
     * subtotal − exempt = taxable identity keeps holding. For inclusive rows the
     * exact stored base is (subtotal − tax_amount) — identical to the PRA
     * SaleValue split. Guarded for prod schema drift (column may lag a deploy).
     */
    private function itemBaseSqlExpr(): string
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_inclusive')) {
            return 'pos_transaction_items.subtotal';
        }
        // Card-save (mode 3): (subtotal − tax_amount) is NOT the base (tax is at the
        // bill's own rate, base was derived at the MENU rate) — divide out the menu
        // rate instead. Exempt lines keep their menu amount (no included tax).
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_menu_rate')) {
            return 'CASE WHEN COALESCE(pos_transactions.tax_inclusive, 0) = 1'
                . ' AND COALESCE(pos_transactions.tax_menu_rate, 0) > 0'
                . ' AND pos_transaction_items.is_tax_exempt = 0'
                . ' THEN (pos_transaction_items.subtotal * 100 / (100 + pos_transactions.tax_menu_rate))'
                . ' WHEN COALESCE(pos_transactions.tax_inclusive, 0) = 1'
                . ' THEN (pos_transaction_items.subtotal - pos_transaction_items.tax_amount)'
                . ' ELSE pos_transaction_items.subtotal END';
        }
        return 'CASE WHEN COALESCE(pos_transactions.tax_inclusive, 0) = 1'
            . ' THEN (pos_transaction_items.subtotal - pos_transaction_items.tax_amount)'
            . ' ELSE pos_transaction_items.subtotal END';
    }

    private function buildItemLevelSummary($transactionIds, $taxRateFilter)
    {
        $base = $this->itemBaseSqlExpr();
        $itemQuery = \App\Models\PosTransactionItem::query()
            ->join('pos_transactions', 'pos_transactions.id', '=', 'pos_transaction_items.transaction_id')
            ->whereIn('pos_transaction_items.transaction_id', $transactionIds);

        if ($taxRateFilter === 'exempt') {
            $itemQuery->where('pos_transaction_items.is_tax_exempt', true);
        } elseif ($taxRateFilter !== null && $taxRateFilter !== '') {
            $rate = (float) $taxRateFilter;
            $itemQuery->where('pos_transaction_items.is_tax_exempt', false)
                ->where('pos_transaction_items.tax_rate', $rate);
        }

        return $itemQuery->selectRaw("
            COUNT(DISTINCT pos_transaction_items.transaction_id) as total_invoices,
            COALESCE(SUM({$base}), 0) as total_sales,
            COALESCE(SUM(pos_transaction_items.tax_amount), 0) as total_tax,
            COALESCE(SUM(CASE WHEN pos_transaction_items.is_tax_exempt = true THEN {$base} ELSE 0 END), 0) as total_exempt,
            COALESCE(SUM(CASE WHEN pos_transaction_items.is_tax_exempt = false THEN {$base} ELSE 0 END), 0) as total_taxable
        ")->first();
    }

    private function getItemLevelValuesForTransactions($transactions, $taxRateFilter)
    {
        $transactionIds = $transactions->pluck('id')->toArray();
        if (empty($transactionIds)) return [];

        $base = $this->itemBaseSqlExpr();
        $itemQuery = \App\Models\PosTransactionItem::query()
            ->join('pos_transactions', 'pos_transactions.id', '=', 'pos_transaction_items.transaction_id')
            ->whereIn('pos_transaction_items.transaction_id', $transactionIds);

        if ($taxRateFilter === 'exempt') {
            $itemQuery->where('pos_transaction_items.is_tax_exempt', true);
        } elseif ($taxRateFilter !== null && $taxRateFilter !== '') {
            $rate = (float) $taxRateFilter;
            $itemQuery->where('pos_transaction_items.is_tax_exempt', false)
                ->where('pos_transaction_items.tax_rate', $rate);
        } else {
            return [];
        }

        return $itemQuery->selectRaw("
            pos_transaction_items.transaction_id,
            COALESCE(SUM({$base}), 0) as item_subtotal,
            COALESCE(SUM(pos_transaction_items.tax_amount), 0) as item_tax,
            COALESCE(SUM(CASE WHEN pos_transaction_items.is_tax_exempt = true THEN {$base} ELSE 0 END), 0) as item_exempt,
            COALESCE(SUM(CASE WHEN pos_transaction_items.is_tax_exempt = false THEN {$base} ELSE 0 END), 0) as item_taxable
        ")->groupBy('pos_transaction_items.transaction_id')->get()->keyBy('transaction_id')->toArray();
    }

    public function taxReports(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Local Invoices tab is ADMIN-ONLY — cashiers are always forced to PRA.
        $tab = ($request->get('tab') === 'local' && auth('pos')->user()?->isPosAdmin()) ? 'local' : 'pra';

        $taxRateFilter = $request->filled('tax_rate') ? $request->tax_rate : null;

        $baseQuery = $this->buildTaxReportQuery($request, $tab, true);
        $transactions = $baseQuery->paginate(50)->appends($request->all());

        $itemValues = [];
        if ($taxRateFilter) {
            $allIdsQuery = $this->buildTaxReportQuery($request, $tab, true);
            $allIds = $allIdsQuery->pluck('id')->toArray();

            $summary = $this->buildItemLevelSummary($allIds, $taxRateFilter);
            $summary->total_discount = 0;

            $itemValues = $this->getItemLevelValuesForTransactions($transactions, $taxRateFilter);
        } else {
            $summaryQuery = $this->buildTaxReportQuery($request, $tab, true);
            $summary = $summaryQuery->reorder()->selectRaw('
                COUNT(*) as total_invoices,
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(SUM(discount_amount), 0) as total_discount,
                COALESCE(SUM(subtotal - discount_amount - COALESCE(exempt_amount, 0)), 0) as total_taxable,
                COALESCE(SUM(tax_amount), 0) as total_tax,
                COALESCE(SUM(exempt_amount), 0) as total_exempt
            ')->first();
        }

        $dateLabel = $this->getReportDateLabel($request);

        $taxRateLabel = 'All Taxes';
        if ($taxRateFilter) {
            $taxRateLabel = $taxRateFilter === 'exempt' ? 'Exempt Items Only' : $taxRateFilter . '% Tax Only';
        }

        $hasPinSet = !empty($company->confidential_pin);
        $localCount = 0;
        $user = auth('pos')->user();
        $availableRates = $this->availableTaxRates($companyId, $tab, $company);

        return view('pos.tax-reports', compact('company', 'transactions', 'summary', 'dateLabel', 'taxRateLabel', 'tab', 'hasPinSet', 'localCount', 'user', 'itemValues', 'taxRateFilter', 'availableRates'));
    }

    public function exportTaxReportCsv(Request $request)
    {
        if ($r = $this->planGate('reports_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Local export is ADMIN-ONLY — cashiers always export PRA data.
        $tab = ($request->get('tab') === 'local' && auth('pos')->user()?->isPosAdmin()) ? 'local' : 'pra';

        $taxRateFilter = $request->filled('tax_rate') ? $request->tax_rate : null;

        $query = $this->buildTaxReportQuery($request, $tab, (bool)$taxRateFilter);
        $transactions = $query->get();

        $dateLabel = $this->getReportDateLabel($request);
        $taxRateLabel = 'All Taxes';
        if ($taxRateFilter) {
            $taxRateLabel = $taxRateFilter === 'exempt' ? 'Exempt Items' : $taxRateFilter . '% Tax';
        }

        $itemValues = [];
        if ($taxRateFilter) {
            $itemValues = $this->getItemLevelValuesForTransactions($transactions, $taxRateFilter);
        }

        $filename = 'NestPOS_Tax_Report_' . str_replace([' ', '/', '(', ')'], '_', $taxRateLabel) . '_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($transactions, $taxRateFilter, $itemValues, $taxRateLabel) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            if ($taxRateFilter) {
                fputcsv($file, [
                    'POS Invoice Number',
                    'PRA Fiscal Invoice Number',
                    'Invoice Date',
                    'Customer Name',
                    'Payment Method',
                    $taxRateLabel . ' Value (PKR)',
                    $taxRateLabel . ' Tax Amount (PKR)',
                    $taxRateLabel . ' Total (PKR)',
                    'Terminal Name',
                    'PRA Status',
                ]);

                $totalValue = 0;
                $totalTax = 0;
                $totalWithTax = 0;

                foreach ($transactions as $t) {
                    $iv = $itemValues[$t->id] ?? null;
                    if (!$iv) continue;

                    $itemSub = (float)($iv['item_subtotal'] ?? 0);
                    $itemTax = (float)($iv['item_tax'] ?? 0);
                    $itemTotal = $itemSub + $itemTax;

                    $totalValue += $itemSub;
                    $totalTax += $itemTax;
                    $totalWithTax += $itemTotal;

                    fputcsv($file, [
                        $t->invoice_number,
                        $t->pra_invoice_number ?? 'N/A',
                        $t->created_at->format('d/m/Y H:i'),
                        $t->customer_name ?? 'Walk-in',
                        ucwords(str_replace('_', ' ', $t->payment_method)),
                        number_format($itemSub, 2, '.', ''),
                        number_format($itemTax, 2, '.', ''),
                        number_format($itemTotal, 2, '.', ''),
                        $t->terminal?->terminal_name ?? 'N/A',
                        strtoupper($t->pra_status ?? 'N/A'),
                    ]);
                }

                fputcsv($file, []);
                fputcsv($file, ['SUMMARY — ' . $taxRateLabel]);
                fputcsv($file, ['Invoices with ' . $taxRateLabel . ' items', count(array_filter($itemValues, fn($v) => ($v['item_subtotal'] ?? 0) > 0))]);
                fputcsv($file, [$taxRateLabel . ' Value (PKR)', number_format($totalValue, 2, '.', '')]);
                fputcsv($file, [$taxRateLabel . ' Tax Amount (PKR)', number_format($totalTax, 2, '.', '')]);
                fputcsv($file, [$taxRateLabel . ' Total (PKR)', number_format($totalWithTax, 2, '.', '')]);
            } else {
                fputcsv($file, [
                    'POS Invoice Number',
                    'PRA Fiscal Invoice Number',
                    'Invoice Date',
                    'Customer Name',
                    'Payment Method',
                    'Subtotal (PKR)',
                    'Discount Amount (PKR)',
                    'Taxable Amount (PKR)',
                    'Tax Exempt Amount (PKR)',
                    'Tax Rate (%)',
                    'Tax Amount (PKR)',
                    'Total Amount (PKR)',
                    'Terminal Name',
                    'PRA Status',
                ]);

                foreach ($transactions as $t) {
                    fputcsv($file, [
                        $t->invoice_number,
                        $t->pra_invoice_number ?? 'N/A',
                        $t->created_at->format('d/m/Y H:i'),
                        $t->customer_name ?? 'Walk-in',
                        ucwords(str_replace('_', ' ', $t->payment_method)),
                        number_format($t->subtotal, 2, '.', ''),
                        number_format($t->discount_amount, 2, '.', ''),
                        number_format($t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0), 2, '.', ''),
                        number_format($t->exempt_amount ?? 0, 2, '.', ''),
                        number_format($t->tax_rate, 2, '.', ''),
                        number_format($t->tax_amount, 2, '.', ''),
                        number_format($t->total_amount, 2, '.', ''),
                        $t->terminal?->terminal_name ?? 'N/A',
                        strtoupper($t->pra_status ?? 'N/A'),
                    ]);
                }

                fputcsv($file, []);
                fputcsv($file, ['SUMMARY']);
                fputcsv($file, ['Total Invoices', $transactions->count()]);
                fputcsv($file, ['Total Sales Amount (PKR)', number_format($transactions->sum('total_amount'), 2, '.', '')]);
                fputcsv($file, ['Total Discount Amount (PKR)', number_format($transactions->sum('discount_amount'), 2, '.', '')]);
                fputcsv($file, ['Total Taxable Amount (PKR)', number_format($transactions->sum(fn($t) => $t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0)), 2, '.', '')]);
                fputcsv($file, ['Total Tax Exempt Amount (PKR)', number_format($transactions->sum('exempt_amount'), 2, '.', '')]);
                fputcsv($file, ['Total Tax Amount (PKR)', number_format($transactions->sum('tax_amount'), 2, '.', '')]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportTaxReportPdf(Request $request)
    {
        if ($r = $this->planGate('reports_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Local export is ADMIN-ONLY — cashiers always export PRA data.
        $tab = ($request->get('tab') === 'local' && auth('pos')->user()?->isPosAdmin()) ? 'local' : 'pra';

        $taxRateFilter = $request->filled('tax_rate') ? $request->tax_rate : null;

        $query = $this->buildTaxReportQuery($request, $tab, (bool)$taxRateFilter);
        $transactions = $query->get();

        $itemValues = [];
        if ($taxRateFilter) {
            $allIds = $transactions->pluck('id')->toArray();
            $summary = $this->buildItemLevelSummary($allIds, $taxRateFilter);
            $summary->total_discount = 0;
            $itemValues = $this->getItemLevelValuesForTransactions($transactions, $taxRateFilter);
        } else {
            $summaryQuery = $this->buildTaxReportQuery($request, $tab, false);
            $summary = $summaryQuery->reorder()->selectRaw('
                COUNT(*) as total_invoices,
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(SUM(discount_amount), 0) as total_discount,
                COALESCE(SUM(subtotal - discount_amount - COALESCE(exempt_amount, 0)), 0) as total_taxable,
                COALESCE(SUM(tax_amount), 0) as total_tax,
                COALESCE(SUM(exempt_amount), 0) as total_exempt
            ')->first();
        }

        $dateLabel = $this->getReportDateLabel($request);
        // taxRateLabel is built via __() so it translates at request-locale time
        // (the locale is already set by SetPosLocale middleware before this runs).
        // The download filename always uses safe ASCII derived from the raw filter
        // value so it never embeds Urdu script into a filename.
        if ($taxRateFilter) {
            $taxRateLabel = $taxRateFilter === 'exempt'
                ? __('pos.tr_exempt_only')
                : __('pos.tr_tax_rate_pct', ['rate' => $taxRateFilter]);
        } else {
            $taxRateLabel = __('pos.tr_all_taxes');
        }

        $filenamePart = $taxRateFilter
            ? ($taxRateFilter === 'exempt' ? 'Exempt' : $taxRateFilter . 'pct')
            : 'All_Taxes';
        $filename = 'NestPOS_Tax_Report_' . $filenamePart . '_' . now()->format('Ymd_His') . '.pdf';

        return $this->renderReportPdf(
            'pos.tax-report-pdf',
            compact('company', 'transactions', 'summary', 'dateLabel', 'taxRateLabel', 'taxRateFilter', 'itemValues'),
            $filename,
            'landscape'
        );
    }

    public function services()
    {
        $companyId = app('currentCompanyId');
        $services = PosService::where('company_id', $companyId)->orderBy('name')->get();
        return view('pos.services', compact('services'));
    }

    public function storeService(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        PosService::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'tax_rate' => $request->tax_rate ?? 0,
            'is_active' => true,
            'is_tax_exempt' => $request->has('is_tax_exempt'),
        ]);

        return back()->with('success', __('pos.service_added_success'));
    }

    public function updateService(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $service = PosService::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
        ]);

        $service->update([
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'tax_rate' => $request->tax_rate ?? $service->tax_rate,
            'is_active' => $request->has('is_active'),
            'is_tax_exempt' => $request->has('is_tax_exempt'),
        ]);

        return back()->with('success', __('pos.service_updated_success'));
    }

    public function deleteService($id)
    {
        $companyId = app('currentCompanyId');
        PosService::where('company_id', $companyId)->findOrFail($id)->delete();
        return back()->with('success', __('pos.service_deleted'));
    }

    // ── Deals (Jul 2026) — fast-food combo promos at one promo price ──────────

    /**
     * Plan gate (Aug 2026 package matrix). Returns a redirect (pages) or
     * aborts 403 (JSON) when the company's plan lacks the premium column.
     */
    private function planGate(string $planColumn)
    {
        $company = Company::find(app('currentCompanyId'));
        if (!PosFeatureService::planAllows($company, $planColumn)) {
            if (request()->expectsJson()) {
                abort(403, __('pos.plan_locked_feature'));
            }
            return redirect()->route('pos.billing')->with('error', __('pos.plan_locked_feature'));
        }
        return null;
    }

    public function deals()
    {
        if ($r = $this->planGate('deals_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $deals = PosDeal::where('company_id', $companyId)
            ->with('items')
            ->orderBy('name')
            ->get();
        $products = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price']);
        // Company-scoped product-name lookup for the list table (deal items store
        // only pos_product_id; PosProduct has no global scope).
        $productNames = $products->pluck('name', 'id');
        return view('pos.deals', compact('deals', 'products', 'productNames'));
    }

    /**
     * Shared validation + normalization for storeDeal/updateDeal. Returns
     * [attrs, components] where components = validated company-scoped
     * [{pos_product_id, quantity}] rows.
     */
    private function validateDealRequest(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0|max:10000000',
            'active_days' => 'nullable|array',
            'active_days.*' => 'integer|min:1|max:7',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'items' => 'required|array|min:1|max:30',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',
        ]);

        // Tamper-safe: every component product must belong to THIS company.
        $productIds = collect($data['items'])->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
        $ownedIds = PosProduct::where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->pluck('id');
        if ($ownedIds->count() !== $productIds->count()) {
            abort(422, 'Invalid product selected for this deal.');
        }

        // Merge duplicate product rows (same product picked twice → sum qty).
        $components = [];
        foreach ($data['items'] as $row) {
            $pid = (int) $row['product_id'];
            $components[$pid] = ($components[$pid] ?? 0) + (int) $row['quantity'];
        }

        $attrs = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => round((float) $data['price'], 2),
            'active_days' => array_values(array_unique(array_map('intval', $data['active_days'] ?? []))),
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
        ];

        return [$attrs, $components];
    }

    public function storeDeal(Request $request)
    {
        if ($r = $this->planGate('deals_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        [$attrs, $components] = $this->validateDealRequest($request, $companyId);

        DB::transaction(function () use ($companyId, $attrs, $components) {
            $deal = PosDeal::create(array_merge($attrs, [
                'company_id' => $companyId,
                'is_active' => true,
            ]));
            foreach ($components as $pid => $qty) {
                PosDealItem::create(['deal_id' => $deal->id, 'pos_product_id' => $pid, 'quantity' => $qty]);
            }
        });

        return back()->with('success', __('pos.deal_added_success'));
    }

    public function updateDeal(Request $request, $id)
    {
        if ($r = $this->planGate('deals_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $deal = PosDeal::where('company_id', $companyId)->findOrFail($id);
        [$attrs, $components] = $this->validateDealRequest($request, $companyId);
        $attrs['is_active'] = $request->has('is_active');

        DB::transaction(function () use ($deal, $attrs, $components) {
            $deal->update($attrs);
            $deal->items()->delete();
            foreach ($components as $pid => $qty) {
                PosDealItem::create(['deal_id' => $deal->id, 'pos_product_id' => $pid, 'quantity' => $qty]);
            }
        });

        return back()->with('success', __('pos.deal_updated_success'));
    }

    public function deleteDeal($id)
    {
        if ($r = $this->planGate('deals_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $deal = PosDeal::where('company_id', $companyId)->findOrFail($id);
        DB::transaction(function () use ($deal) {
            $deal->items()->delete();
            $deal->delete();
        });
        // Sold bills keep their own deal_snapshot — deleting a deal never
        // touches historical transactions.
        return back()->with('success', __('pos.deal_deleted'));
    }

    public function getTaxRate(Request $request)
    {
        $method = $request->payment_method ?? 'cash';
        $company = Company::find(app('currentCompanyId'));
        $rate = PosTaxRule::getRateForMethod($method, $company);
        return response()->json(['tax_rate' => $rate]);
    }

    /**
     * Standalone → PRA upgrade: flips the company onto the PRA-integrated
     * edition (plans + PRA settings become relevant). One-way from the UI —
     * the reverse (PRA → standalone) is an admin decision, not self-serve.
     */
    public function enablePraIntegration(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // PRA integration submits your NTN with every fiscal invoice — require it on file
        // first. NTN is optional at registration; the company adds it here in the profile.
        if (empty($company->ntn)) {
            return redirect()->route('pos.business-profile')
                ->with('error', __('pos.ntn_required_pra_integration'));
        }

        if (($company->pos_integration_mode ?? 'pra') === 'standalone') {
            $company->pos_integration_mode = 'pra';
            $company->save();
        }

        return redirect()->route('pos.pra-settings')->with('success', __('pos.pra_integration_enabled'));
    }

    public function togglePra(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // Standalone edition has ZERO government integration — PRA reporting can
        // never be flipped on (sales would queue for a submission that must fail).
        // The sale-screen toggle is hidden for standalone; this guards direct POSTs.
        if (($company->pos_integration_mode ?? 'pra') === 'standalone') {
            return response()->json([
                'success' => false,
                'enabled' => false,
                'message' => __('pos.pra_not_available_standalone'),
            ], 422);
        }

        // Per-cashier toggle (owner rule Jul 2026): flip ONLY the acting user's own
        // switch — the company-wide flag stays untouched, so one cashier turning
        // reporting on/off NEVER affects another cashier or the admin.
        $togglingUser = auth('pos')->user();
        $effectiveNow = $togglingUser->praReportingEnabled($company);

        // Owner rule (20 Jul 2026): cashiers can NOT flip their own PRA reporting —
        // the admin ASSIGNS each cashier Online/Offline from the Team page. The sale
        // screen shows cashiers a read-only badge; this guards direct POSTs.
        if ($togglingUser->isPosCashier()) {
            return response()->json([
                'success' => false,
                'enabled' => (bool) $effectiveNow,
                'message' => __('pos.pra_reporting_set_by_admin'),
            ], 403);
        }

        // Turning PRA Reporting ON requires an NTN on file (submitted with every fiscal
        // invoice). Turning it OFF is always allowed. NTN is optional at registration.
        if (!$effectiveNow && empty($company->ntn)) {
            return response()->json([
                'success' => false,
                'enabled' => false,
                'message' => __('pos.ntn_required_pra_on'),
            ], 422);
        }

        $togglingUser->pra_reporting_enabled = !$effectiveNow;
        $togglingUser->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $togglingUser->pra_reporting_enabled,
            'message' => $togglingUser->pra_reporting_enabled
                ? __('pos.pra_reporting_enabled_self')
                : __('pos.pra_reporting_disabled_self'),
        ]);
    }

    /**
     * Customize POS → Local Bills — persist "auto-archive local bills on day-close".
     * When ON, EVERY day-close (manual or the 6 AM auto command) archives that day's
     * local/provisional bills to the Archive Portal. Rows are kept, never deleted.
     */
    public function toggleAutoPurgeLocal(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        $company = Company::find(app('currentCompanyId'));
        $company->pos_auto_purge_local_on_dayclose = $request->boolean('enabled');
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->pos_auto_purge_local_on_dayclose,
            'message' => $company->pos_auto_purge_local_on_dayclose ? __('pos.auto_archive_dayclose_enabled') : __('pos.auto_archive_dayclose_disabled'),
        ]);
    }

    /**
     * Customize POS → Local Billing — persist the day-close wash policy (F1, Jul 2026):
     * what happens to reporting-OFF FINAL local bills and to PROVISIONAL bills at
     * day-close ('save' = archive | 'delete'), and whether customer spend snapshots
     * survive a delete. Admin/manager only — a standing company decision.
     */
    /**
     * Customize POS → Kitchen Display — KDS device auto-prints the KOT for every
     * NEW incoming order it sees (P6, F5). Distinct from auto_print_kot which is
     * the cashier-side print at sale time.
     */
    public function toggleKdsAutoPrint(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }

        $validated = $request->validate(['enabled' => 'required|boolean']);

        $company = Company::find(app('currentCompanyId'));
        $company->pos_kds_auto_print = (bool) $validated['enabled'];
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->pos_kds_auto_print,
            'message' => $company->pos_kds_auto_print ? __('pos.kds_auto_print_enabled') : __('pos.kds_auto_print_disabled'),
        ]);
    }

    public function updateLocalBillingSettings(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }

        $validated = $request->validate([
            'final_action' => 'required|in:save,delete',
            'provisional_action' => 'required|in:save,delete,carry,finalize',
            'spend_persist' => 'required|boolean',
        ]);

        $company = Company::find(app('currentCompanyId'));
        $company->pos_dayclose_final_local_action = $validated['final_action'];
        $company->pos_dayclose_provisional_action = $validated['provisional_action'];
        $company->pos_customer_spend_persist = (bool) $validated['spend_persist'];
        $company->save();

        return response()->json([
            'success' => true,
            'final_action' => $company->pos_dayclose_final_local_action,
            'provisional_action' => $company->pos_dayclose_provisional_action,
            'spend_persist' => (bool) $company->pos_customer_spend_persist,
            'message' => __('pos.local_billing_policy_saved'),
        ]);
    }

    /**
     * Customize POS → Local Bills — persist "auto day-close at 6:00 AM next morning".
     * When ON, the scheduled pos:auto-dayclose command closes any un-closed prior day
     * at 6:00 AM the NEXT morning (owner rule 23 Jul 2026 — replaced the older
     * "second midnight / 1-day grace" rule; see AutoCloseDayPos + routes/console.php).
     */
    public function toggleAutoDayclose(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        $company = Company::find(app('currentCompanyId'));
        $company->pos_auto_dayclose_24h = $request->boolean('enabled');
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->pos_auto_dayclose_24h,
            'message' => $company->pos_auto_dayclose_24h ? __('pos.auto_dayclose_enabled') : __('pos.auto_dayclose_disabled'),
        ]);
    }

    /**
     * Day Close page — persist the company's business-day cutoff time
     * ("Din band hone ka waqt", owner request 30 Jul 2026). Sales before this
     * time count in the PREVIOUS trading day (business_date) and the auto
     * day-close sweep waits for it. Restricted to early morning (00:00–11:30)
     * so a "cutoff" can never swallow daytime trade. PRA/FBR & tax reports
     * stay on real created_at — this never shifts the legal record.
     */
    public function updateDaycloseCutoff(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }

        $cutoff = (string) $request->input('cutoff', '');
        if (!preg_match('/^([01]\d):(00|30)$/', $cutoff) || $cutoff >= '12:00') {
            return response()->json(['success' => false, 'message' => __('pos.dayclose_time_range')], 422);
        }

        $company = Company::find(app('currentCompanyId'));
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_business_day_cutoff')) {
            $company->pos_business_day_cutoff = $cutoff;
            $company->save();
            \App\Services\PosBusinessDay::forgetCutoff($company->id);
        } else {
            return response()->json(['success' => false, 'message' => __('pos.setting_not_available_try_later')], 503);
        }

        return response()->json([
            'success' => true,
            'cutoff' => $cutoff,
            'message' => __('pos.dayclose_time_saved', ['time' => \Carbon\Carbon::createFromFormat('H:i', $cutoff)->format('g:i A')]),
        ]);
    }

    /**
     * Phase 4 — flip the "auto-print receipt on sale" setting.
     * Reads/writes the existing companies.print_on_pay column (default true).
     */
    public function toggleAutoPrint(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $company->print_on_pay = ! (bool) $company->print_on_pay;
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->print_on_pay,
            'message' => $company->print_on_pay ? __('pos.auto_print_enabled') : __('pos.auto_print_disabled'),
        ]);
    }

    /**
     * Phase 5+ — flip the "auto-print kitchen ticket on sale" setting.
     * Only meaningful for restaurant_mode companies; we still persist the bit
     * for everyone but the toggle pill is only rendered for restaurants.
     */
    public function toggleAutoKot(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $features = \App\Services\PosFeatureService::forCompany($company);
        if (!$features->kot) {
            return response()->json([
                'success' => false,
                'message' => __('pos.auto_kot_requires_feature'),
            ], 422);
        }

        $company->auto_print_kot = ! (bool) $company->auto_print_kot;
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->auto_print_kot,
            'message' => $company->auto_print_kot ? __('pos.auto_kot_enabled') : __('pos.auto_kot_disabled'),
        ]);
    }

    public function terminals()
    {
        $companyId = app('currentCompanyId');
        $terminals = PosTerminal::where('company_id', $companyId)->orderBy('terminal_name')->get();
        return view('pos.terminals', compact('terminals'));
    }

    public function storeTerminal(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'terminal_name' => 'required|string|max:255',
            'terminal_code' => 'required|string|max:100|unique:pos_terminals,terminal_code',
            'location' => 'nullable|string|max:255',
        ]);

        PosTerminal::create([
            'company_id' => $companyId,
            'terminal_name' => $request->terminal_name,
            'terminal_code' => $request->terminal_code,
            'location' => $request->location,
            'is_active' => true,
        ]);

        return back()->with('success', __('pos.terminal_added_success'));
    }

    public function updateTerminal(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $terminal = PosTerminal::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'terminal_name' => 'required|string|max:255',
            'terminal_code' => 'required|string|max:100|unique:pos_terminals,terminal_code,' . $id,
            'location' => 'nullable|string|max:255',
        ]);

        $terminal->update([
            'terminal_name' => $request->terminal_name,
            'terminal_code' => $request->terminal_code,
            'location' => $request->location,
            'is_active' => $request->has('is_active'),
        ]);

        return back()->with('success', __('pos.terminal_updated_success'));
    }

    public function deleteTerminal($id)
    {
        $companyId = app('currentCompanyId');
        $terminal = PosTerminal::where('company_id', $companyId)->findOrFail($id);

        if ($terminal->transactions()->exists()) {
            return back()->with('error', __('pos.cannot_delete_terminal_transactions'));
        }

        $terminal->delete();
        return back()->with('success', __('pos.terminal_deleted'));
    }

    public function praSettings(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = auth('pos')->user();

        if ($request->isMethod('post')) {
            if ($user->posCashierBlocked()) {
                return back()->with('error', __('pos.only_company_admin_change_settings'));
            }

            $request->validate([
                'pra_environment' => 'required|in:sandbox,production',
                'pra_connection_mode' => 'nullable|in:cloud,fiscal_device',
                'pra_pos_id' => 'nullable|string',
                'pra_production_token' => 'nullable|string',
                'pra_proxy_url' => 'nullable|url',
                'receipt_printer_size' => 'nullable|in:80mm,58mm',
            ]);

            $updateData = [
                'pra_environment' => $request->pra_environment,
                'receipt_printer_size' => $request->receipt_printer_size ?? '80mm',
            ];

            if ($request->filled('pra_connection_mode')) {
                $updateData['pra_connection_mode'] = $request->pra_connection_mode;

                // Fiscal Device submissions only happen from the shop PC — the desktop agent is mandatory
                // AND must be in Agent Sync submission mode (server never direct-submits, PRA Code 112).
                if ($request->pra_connection_mode === 'fiscal_device') {
                    // Task 117: fiscal_device mints an agent key = NEW Desktop App
                    // pairing → plan-gated (Business+). Already-paired shops are
                    // grandfathered (key exists — never break a live agent).
                    if (empty($company->agent_api_key)
                        && !\App\Services\PosFeatureService::planAllows($company, 'offline_enabled')) {
                        return back()->with('error', 'Fiscal Device mode ke liye Desktop App zaroori hai, jo aap ke mojooda package mein shamil nahi — Business ya us se upar ke package par upgrade karein.');
                    }
                    $updateData['agent_enabled'] = true;
                    $updateData['agent_submits_pra'] = true;
                    if (empty($company->agent_api_key)) {
                        $updateData['agent_api_key'] = 'tnk_' . \Illuminate\Support\Str::random(48);
                    }
                }
                // NOTE: switching BACK to 'cloud' deliberately touches NEITHER agent flag —
                // submission mode stays whatever the shop chose on /pos/agent, and the agent
                // stays connected for silent printing.
            }

            if ($request->filled('pra_pos_id')) {
                $updateData['pra_pos_id'] = $request->pra_pos_id;
            }

            if ($request->filled('pra_production_token')) {
                $updateData['pra_production_token'] = $request->pra_production_token;
            }

            $updateData['pra_proxy_url'] = $request->pra_proxy_url ?: null;

            $company->update($updateData);

            if ($request->filled('confidential_pin')) {
                $request->validate([
                    'confidential_pin' => 'string|min:4|max:6|regex:/^\d+$/',
                ]);
                $company->update([
                    'confidential_pin' => bcrypt($request->confidential_pin),
                ]);
                return back()->with('success', __('pos.settings_pin_updated'));
            }

            if ($request->has('remove_pin') && $request->remove_pin) {
                $company->update(['confidential_pin' => null]);
                return back()->with('success', __('pos.settings_pin_removed'));
            }

            return back()->with('success', __('pos.pra_settings_updated'));
        }

        $praLogs = PraLog::where('company_id', $companyId)->orderBy('created_at', 'desc')->take(20)->get();
        $hasPinSet = !empty($company->confidential_pin);
        return view('pos.pra-settings', compact('company', 'praLogs', 'hasPinSet'));
    }

    public function verifyPin(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $lockKey = 'pin_lockout_' . $companyId;
        $attemptsKey = 'pin_attempts_' . $companyId;

        if (cache()->get($lockKey)) {
            $remaining = cache()->get($lockKey) - now()->timestamp;
            return response()->json([
                'success' => false,
                'message' => __('pos.too_many_wrong_attempts_minutes', ['minutes' => ceil($remaining / 60)]),
                'locked' => true,
            ], 429);
        }

        if (empty($company->confidential_pin)) {
            return response()->json(['success' => false, 'message' => __('pos.confidential_pin_not_set')], 400);
        }

        $pin = $request->input('pin', '');
        if (\Hash::check($pin, $company->confidential_pin)) {
            cache()->forget($attemptsKey);
            session(['confidential_pin_verified' => true, 'confidential_pin_verified_at' => now()->timestamp]);
            return response()->json(['success' => true, 'message' => __('pos.pin_verified')]);
        }

        $attempts = (int) cache()->get($attemptsKey, 0) + 1;
        cache()->put($attemptsKey, $attempts, 900);

        if ($attempts >= 5) {
            cache()->put($lockKey, now()->addMinutes(15)->timestamp, 900);
            cache()->forget($attemptsKey);
            return response()->json([
                'success' => false,
                'message' => __('pos.too_many_wrong_attempts_locked'),
                'locked' => true,
            ], 429);
        }

        return response()->json([
            'success' => false,
            'message' => __('pos.wrong_pin_attempts_remaining', ['count' => 5 - $attempts]),
            'remaining' => 5 - $attempts,
        ], 401);
    }

    public function checkPinSession()
    {
        $verified = session('confidential_pin_verified', false);
        $verifiedAt = session('confidential_pin_verified_at', 0);
        $isValid = $verified && (now()->timestamp - $verifiedAt) < 1800;

        return response()->json(['verified' => $isValid]);
    }

    public function posTeam(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        // Custom Access (Task #111): an explicit 'team' grant lets a cashier in.
        if ($user->isPosCashier() && $user->posCustomAllows('team') !== true) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.access_denied'));
        }

        $team = User::where('company_id', $companyId)
            ->whereIn('pos_role', ['pos_admin', 'pos_manager', 'pos_cashier', 'pos_kitchen', 'pos_waiter', 'pos_delivery'])
            ->orderByRaw("CASE WHEN pos_role = 'pos_admin' THEN 0 WHEN pos_role = 'pos_manager' THEN 1 WHEN pos_role = 'pos_cashier' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        // Owner request (Jul 2026): admin can VIEW team passwords. Decrypt the
        // stored copy server-side, keyed by user id — ONLY for team roles (the
        // owner/admin row never shows one) and only on this admin-gated page.
        // Accounts created before this feature have no copy until the admin
        // sets a new password (hashes are irreversible).
        $teamPasswords = [];
        foreach ($team as $member) {
            if (in_array($member->pos_role, ['pos_cashier', 'pos_manager', 'pos_kitchen', 'pos_waiter', 'pos_delivery'], true)
                && !empty($member->pos_team_password_enc)) {
                try {
                    $teamPasswords[$member->id] = \Illuminate\Support\Facades\Crypt::decryptString($member->pos_team_password_enc);
                } catch (\Throwable $e) {
                    // APP_KEY rotated or corrupt payload — treat as "not stored".
                }
            }
        }

        // PRA assignment column (owner rule 20 Jul 2026): the team page shows and
        // sets each cashier's Online/Offline PRA status, so it needs the company
        // for the inherit-fallback in praReportingEnabled().
        $company = Company::find($companyId);

        return view('pos.team', compact('team', 'teamPasswords', 'company'));
    }

    public function storeCashier(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier() && $user->posCustomAllows('team') !== true) {
            return back()->with('error', __('pos.access_denied'));
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
            'pos_role' => 'nullable|in:pos_cashier,pos_manager,pos_kitchen,pos_waiter,pos_delivery',
        ]);

        $newRole = $request->input('pos_role') ?: 'pos_cashier';

        // Team-account quota (paid-plan package limits, Jul 2026):
        // user_limit counts ADDED accounts only — the owner's pos_admin account
        // is EXEMPT. Starter 1 = owner + 1, Business 5, Pro 10, Unlimited -1.
        // Managers count toward the limit exactly like cashiers.
        // Kitchen (P5, F4) + Waiter (P7, F6) + Delivery Manager accounts are
        // limit-EXEMPT — confined roles (owner, 20 Jul 2026).
        if (!in_array($newRole, ['pos_kitchen', 'pos_waiter', 'pos_delivery'], true)) {
            $quota = \App\Services\PlanLimitService::canAddPosUser($companyId);
            if (!($quota['allowed'] ?? true)) {
                return back()->with('error', $quota['reason']);
            }
        }

        $newUserData = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'company_id' => $companyId,
            'role' => 'employee',
            'pos_role' => $newRole,
            'is_active' => true,
        ];
        // Owner request (Jul 2026): POS admin can VIEW team passwords on
        // /pos/team. Hashes are irreversible, so keep an encrypted copy —
        // decrypted ONLY on the Team page for non-cashier viewers.
        // PROD schema-drift guard: skip if the migration hasn't landed yet.
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_team_password_enc')) {
            $newUserData['pos_team_password_enc'] = \Illuminate\Support\Facades\Crypt::encryptString($request->password);
        }
        User::create($newUserData);

        $roleLabel = ['pos_manager' => __('pos.role_manager'), 'pos_kitchen' => __('pos.role_kitchen'), 'pos_waiter' => __('pos.role_waiter'), 'pos_delivery' => __('pos.role_delivery_manager')][$newRole] ?? __('pos.role_cashier');
        return back()->with('success', __('pos.account_created_success', ['role' => $roleLabel]));
    }

    /**
     * Team page — ASSIGN a cashier's PRA Reporting status (owner rule 20 Jul 2026):
     * cashiers can no longer flip their own toggle on the sale screen; the admin
     * sets each cashier Online (PRA reporting) or Offline here. Managers/admins
     * keep their own sale-screen toggle, so this endpoint covers cashiers only.
     */
    public function setCashierPra(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if (!$user || ($user->isPosCashier() && $user->posCustomAllows('team') !== true)) {
            return back()->with('error', __('pos.access_denied'));
        }

        $request->validate(['enabled' => 'required|boolean']);
        $enable = (bool) $request->boolean('enabled');

        $cashier = User::where('company_id', $companyId)
            ->where('pos_role', 'pos_cashier')
            ->findOrFail($id);

        $company = Company::find($companyId);

        // Standalone edition has no PRA integration; and turning reporting ON
        // requires an NTN on file (mirrors togglePra's server-side guards).
        if ($enable && ($company->pos_integration_mode ?? 'pra') === 'standalone') {
            return back()->with('error', __('pos.pra_not_available_standalone'));
        }
        if ($enable && empty($company->ntn)) {
            return back()->with('error', __('pos.ntn_required_pra_on'));
        }

        $cashier->pra_reporting_enabled = $enable;
        $cashier->save();

        return back()->with('success', $enable
            ? __('pos.cashier_online_now', ['name' => $cashier->name])
            : __('pos.cashier_offline_now', ['name' => $cashier->name]));
    }

    /**
     * Team page — Custom Access (Task #111, owner-approved 2 Aug 2026):
     * per-member feature tick-boxes overlaying the role defaults. Saved as a
     * JSON array of feature keys in users.pos_custom_access; custom_enabled=0
     * clears the set (member reverts to plain role behavior). Only cashier +
     * manager rows accept a set — confined roles stay confined (PosAuth).
     */
    public function setCashierAccess(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if (!$user || ($user->isPosCashier() && $user->posCustomAllows('team') !== true)) {
            return back()->with('error', __('pos.access_denied'));
        }

        // PROD schema-drift guard: migration not landed yet → clear message.
        if (!\App\Services\PosAccessService::columnReady()) {
            return back()->with('error', __('pos.custom_access_unavailable'));
        }

        // Plan gate (Aug 2026): Custom Access is Unlimited-only.
        if (!PosFeatureService::planAllows(Company::find($companyId), 'custom_access_enabled')) {
            return back()->with('error', __('pos.custom_access_plan_locked'));
        }

        $request->validate([
            'custom_enabled' => 'required|boolean',
            'features' => 'nullable|array',
            'features.*' => 'string|in:' . implode(',', \App\Services\PosAccessService::FEATURES),
        ]);

        $member = User::where('company_id', $companyId)
            ->whereIn('pos_role', \App\Services\PosAccessService::CUSTOMIZABLE_ROLES)
            ->findOrFail($id);

        if (!$request->boolean('custom_enabled')) {
            $member->pos_custom_access = null;
            $member->save();
            return back()->with('success', __('pos.custom_access_cleared', ['name' => $member->name]));
        }

        $features = array_values(array_intersect(
            \App\Services\PosAccessService::FEATURES,
            (array) $request->input('features', [])
        ));

        $member->pos_custom_access = json_encode($features);
        $member->save();

        return back()->with('success', __('pos.custom_access_saved', ['name' => $member->name]));
    }

    public function updateCashier(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier() && $user->posCustomAllows('team') !== true) {
            return back()->with('error', __('pos.access_denied'));
        }

        $cashier = User::where('company_id', $companyId)->whereIn('pos_role', ['pos_cashier', 'pos_manager', 'pos_kitchen', 'pos_waiter', 'pos_delivery'])->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email,' . $cashier->id,
            'phone' => 'nullable|string|max:20',
            // Item #7 (owner, Jul 2026): optional password RESET from the team edit
            // row — stored hashes are irreversible, so "view password" is impossible;
            // the admin sets a NEW one instead. Blank = keep the current password.
            'password' => 'nullable|string|min:6|max:100',
        ]);

        $cashier->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
        ]);

        if ($request->filled('password')) {
            $pwUpdate = ['password' => bcrypt($request->password)];
            // Keep the admin-viewable encrypted copy in sync (owner, Jul 2026).
            // PROD schema-drift guard: skip if the migration hasn't landed yet.
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_team_password_enc')) {
                $pwUpdate['pos_team_password_enc'] = \Illuminate\Support\Facades\Crypt::encryptString($request->password);
            }
            $cashier->update($pwUpdate);
        }

        return back()->with('success', __('pos.cashier_updated'));
    }

    // ── Item #1 (Jul 2026): customer saved delivery addresses ──────────────
    // pos_customers.address = "address #1" (default); extras live in
    // pos_customer_addresses (NO FK — shared-table rule; always company-scoped).
    // Cashiers allowed: this is part of the sale flow, not admin config.
    public function apiCustomerAddresses(Request $request)
    {
        $companyId = app('currentCompanyId');
        $customer = \App\Models\PosCustomer::where('company_id', $companyId)
            ->find((int) $request->query('customer_id'));
        if (!$customer) {
            return response()->json(['addresses' => []]);
        }

        $addresses = [];
        if (trim((string) $customer->address) !== '') {
            $addresses[] = ['id' => 0, 'label' => 'Default', 'address' => $customer->address];
        }
        \App\Models\PosCustomerAddress::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->get()
            ->each(function ($a) use (&$addresses) {
                $addresses[] = ['id' => $a->id, 'label' => $a->label, 'address' => $a->address];
            });

        return response()->json(['addresses' => $addresses]);
    }

    public function apiStoreCustomerAddress(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'customer_id' => 'required|integer',
            'address' => 'required|string|max:500',
            'label' => 'nullable|string|max:50',
        ]);

        $customer = \App\Models\PosCustomer::where('company_id', $companyId)
            ->find((int) $request->customer_id);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => __('pos.customer_not_found')], 404);
        }

        // First-ever address becomes the customer's default (address #1) so the
        // legacy pos_customers.address surfaces (chip, exports) stay populated.
        if (trim((string) $customer->address) === '') {
            $customer->update(['address' => trim($request->address)]);
            return response()->json(['success' => true, 'address' => ['id' => 0, 'label' => 'Default', 'address' => $customer->address]]);
        }

        // Duplicate guard (Aug 2026): same text saved twice makes the delete
        // button ambiguous — reject repeats (case-insensitive) incl. the default.
        $newText = mb_strtolower(trim($request->address));
        if (mb_strtolower(trim((string) $customer->address)) === $newText) {
            return response()->json(['success' => false, 'message' => __('pos.address_already_saved')], 422);
        }
        $dup = \App\Models\PosCustomerAddress::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->get(['address'])
            ->contains(fn ($r) => mb_strtolower(trim((string) $r->address)) === $newText);
        if ($dup) {
            return response()->json(['success' => false, 'message' => __('pos.address_already_saved')], 422);
        }

        // Sanity cap — a walk-in POS customer never needs 15+ saved addresses.
        $count = \App\Models\PosCustomerAddress::where('company_id', $companyId)
            ->where('customer_id', $customer->id)->count();
        if ($count >= 15) {
            return response()->json(['success' => false, 'message' => __('pos.address_limit_reached')], 422);
        }

        $addr = \App\Models\PosCustomerAddress::create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'label' => $request->input('label') ?: null,
            'address' => trim($request->address),
        ]);

        return response()->json(['success' => true, 'address' => ['id' => $addr->id, 'label' => $addr->label, 'address' => $addr->address]]);
    }

    /**
     * Delete a saved delivery address (ZFC customer request, Aug 2026): "customer
     * shifted — the OLD saved address must go, right from the sale screen." id=0
     * clears the customer's default address (pos_customers.address); any other id
     * deletes the pos_customer_addresses row. Company-scoped; all POS roles allowed
     * (same as add — this is day-to-day sales work, not admin work).
     */
    public function apiDeleteCustomerAddress(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'customer_id' => 'required|integer',
            'id' => 'required|integer|min:0',
        ]);

        $customer = \App\Models\PosCustomer::where('company_id', $companyId)
            ->find((int) $request->customer_id);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => __('pos.customer_not_found')], 404);
        }

        if ((int) $request->id === 0) {
            $customer->update(['address' => null]);
            return response()->json(['success' => true]);
        }

        $deleted = \App\Models\PosCustomerAddress::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('id', (int) $request->id)
            ->delete();

        return response()->json(['success' => (bool) $deleted]);
    }

    public function toggleCashier($id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier() && $user->posCustomAllows('team') !== true) {
            return back()->with('error', __('pos.access_denied'));
        }

        $cashier = User::where('company_id', $companyId)->whereIn('pos_role', ['pos_cashier', 'pos_manager', 'pos_kitchen', 'pos_waiter', 'pos_delivery'])->findOrFail($id);

        // Reactivating a cashier re-consumes a team-account slot — same gate as
        // storeCashier, otherwise deactivate→create→reactivate bypasses the limit.
        // Kitchen + Waiter + Delivery Manager accounts are limit-EXEMPT (never consume a slot).
        if (!$cashier->is_active && !in_array($cashier->pos_role, ['pos_kitchen', 'pos_waiter', 'pos_delivery'], true)) {
            $quota = \App\Services\PlanLimitService::canAddPosUser($companyId);
            if (!($quota['allowed'] ?? true)) {
                return back()->with('error', $quota['reason']);
            }
        }

        $cashier->update(['is_active' => !$cashier->is_active]);

        return back()->with('success', $cashier->is_active ? __('pos.cashier_activated') : __('pos.cashier_deactivated'));
    }

    public function products()
    {
        $companyId = app('currentCompanyId');
        $products = PosProduct::where('company_id', $companyId)->orderBy('name')->get();
        $company = \App\Models\Company::find($companyId);
        $posType = $company->pos_type ?? 'retail';
        $categoryFields = PosProduct::categoryFields()[$posType] ?? [];
        // For unified Product+Recipe modal: existing ingredients dropdown source
        $ingredients = \App\Models\Ingredient::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'cost_per_unit']);
        // Existing-recipe quick-copy source: products in this company that already have a recipe.
        // Cashier can pick one to auto-populate ingredient rows (then tweak names/qty).
        $existingRecipes = [];
        if (class_exists(\App\Models\ProductRecipe::class)) {
            $recipeRows = \App\Models\ProductRecipe::where('company_id', $companyId)
                ->with(['product:id,name', 'ingredient:id,name,unit,cost_per_unit'])
                ->get();
            $grouped = $recipeRows->groupBy('product_id');
            foreach ($grouped as $productId => $rows) {
                $prodName = optional($rows->first()->product)->name;
                if (!$prodName) continue;
                $items = [];
                foreach ($rows as $r) {
                    if (!$r->ingredient) continue;
                    $items[] = [
                        'ingredient_id'   => (int) $r->ingredient_id,
                        'name'            => $r->ingredient->name,
                        'unit'            => $r->ingredient->unit,
                        'quantity_needed' => (float) $r->quantity_needed,
                    ];
                }
                if (!empty($items)) {
                    $existingRecipes[] = [
                        'product_id'   => (int) $productId,
                        'product_name' => $prodName,
                        'ingredients'  => $items,
                    ];
                }
            }
            usort($existingRecipes, fn($a, $b) => strcasecmp($a['product_name'], $b['product_name']));
        }
        return view('pos.products', compact('products', 'posType', 'categoryFields', 'ingredients', 'existingRecipes'));
    }

    /**
     * Smart Quick-Create endpoint for Simple POS mode.
     * Creates a minimal POS product on the fly when cashier types something
     * not in catalog AND inventory is OFF. Defense in depth: refuses when
     * inventory is enabled (UI must direct user to /pos/products instead).
     *
     * Inputs: name (required), price (optional, defaults 0)
     * Returns: { ok, product:{...} } shaped to slot into Alpine `allProducts`.
     */
    public function apiQuickCreate(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::find($companyId);
        if (!$company) {
            return response()->json(['ok' => false, 'error' => 'Company not found'], 404);
        }
        // Server-side inventory guard — quick-create only allowed in Simple Mode.
        if (!empty($company->inventory_enabled)) {
            return response()->json([
                'ok' => false,
                'error' => 'Quick-create is disabled when inventory mode is on. Add product from Product Management.',
                'reason' => 'inventory_enabled',
            ], 422);
        }
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            return response()->json(['ok' => false, 'error' => 'Name required'], 422);
        }
        $product = PosProduct::create([
            'company_id'    => $companyId,
            'name'          => $name,
            'price'         => $data['price'] ?? 0,
            'cost_price'    => $data['cost_price'] ?? 0,
            'tax_rate'      => 0,
            'is_active'     => true,
            'is_tax_exempt' => false,
            'show_on_sale'  => true, // explicit — never trust the DB default (prod drift)
            'category'      => 'Quick',
            'sku'           => 'QC-' . substr((string) time(), -6) . '-' . strtoupper(substr(uniqid(), -3)),
            'uom'           => 'NOS',
        ]);
        return response()->json([
            'ok' => true,
            'product' => [
                'id'            => $product->id,
                'name'          => $product->name,
                'price'         => (float) $product->price,
                'category'      => $product->category,
                'type'          => 'product',
                'image'         => null,
                'is_tax_exempt' => false,
                'hasRecipe'     => false,
                'stockStatus'   => null,
                'isQuickCreated'=> true,
            ],
        ]);
    }

    /**
     * Inline price update for a freshly quick-created product.
     * Cashier sets the price right after add; this persists it.
     */
    public function apiQuickUpdatePrice(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $data = $request->validate([
            'price' => 'required|numeric|min:0',
        ]);
        $product = PosProduct::where('company_id', $companyId)->where('id', $id)->first();
        if (!$product) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }
        $product->price = $data['price'];
        $product->save();
        return response()->json(['ok' => true, 'price' => (float) $product->price]);
    }

    public function storeProduct(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'category' => 'nullable|string|max:100',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'uom' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'stock_quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'drug_type' => 'nullable|string|max:50',
            'unit_type' => 'nullable|string|max:20',
            'size' => 'nullable|string|max:30',
            'color' => 'nullable|string|max:50',
            'season' => 'nullable|string|max:30',
            'serial_number' => 'nullable|string|max:100',
            'warranty_months' => 'nullable|integer|min:0',
            'imei' => 'nullable|string|max:20',
            'bulk_discount_qty' => 'nullable|integer|min:0',
            'bulk_discount_pct' => 'nullable|numeric|min:0|max:100',
            'service_duration' => 'nullable|integer|min:0',
            'staff_assignment' => 'nullable|string|max:100',
            'vehicle_make' => 'nullable|string|max:50',
            'vehicle_model' => 'nullable|string|max:50',
            'part_number' => 'nullable|string|max:100',
            'box_type' => 'nullable|string|max:50',
        ]);

        $imageName = null;
        if ($request->hasFile('image')) {
            $imageName = $companyId . '_' . time() . '_' . uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
            $request->file('image')->storeAs('products', $imageName, 'public');
        }

        $isExempt = $request->has('is_tax_exempt');
        $data = [
            'company_id' => $companyId,
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'cost_price' => $request->filled('cost_price') ? $request->cost_price : 0,
            'stock_quantity' => $request->filled('stock_quantity') ? (int) $request->stock_quantity : null,
            'low_stock_threshold' => $request->filled('low_stock_threshold') ? (int) $request->low_stock_threshold : 10,
            // Backend hardening: exempt MUST persist tax_rate=0 regardless of what (if anything) UI submitted
            'tax_rate' => $isExempt ? 0 : ($request->tax_rate ?? 0),
            'category' => $request->category,
            'sku' => $request->sku,
            'barcode' => $request->barcode,
            'uom' => $request->uom ?? 'NOS',
            'is_tax_exempt' => $isExempt,
            'show_on_sale' => $request->has('show_on_sale'),
            'image' => $imageName,
            'prescription_required' => $request->has('prescription_required'),
            'weight_based' => $request->has('weight_based'),
            'custom_order' => $request->has('custom_order'),
        ];

        $categoryExtraFields = [
            'batch_number', 'expiry_date', 'drug_type', 'unit_type',
            'size', 'color', 'season', 'serial_number', 'warranty_months', 'imei',
            'bulk_discount_qty', 'bulk_discount_pct', 'service_duration', 'staff_assignment',
            'vehicle_make', 'vehicle_model', 'part_number', 'box_type',
        ];
        foreach ($categoryExtraFields as $field) {
            if ($request->filled($field)) {
                $data[$field] = $request->$field;
            }
        }

        // ═══ Unified Product + Recipe (Single Box) ═══
        // Wraps PosProduct creation AND optional recipe rows in one transaction
        // so a failed ingredient/recipe write rolls back the product too — no orphans.
        // Tracks counts so the cashier sees exactly what landed and what was skipped.
        $recipeAdded = 0;
        $recipeSkipped = 0;
        $product = \DB::transaction(function () use ($data, $request, $companyId, &$recipeAdded, &$recipeSkipped) {
            $product = PosProduct::create($data);

            // Inventory mirror seed: when the inventory module is ON and the
            // cashier gave an opening stock, create the authoritative
            // inventory_stocks row (+ opening movement) so the module and the
            // products page start in sync instead of the module seeing 0.
            $companyRow = \App\Models\Company::find($companyId);
            if ($companyRow && $companyRow->inventory_enabled && $product->stock_quantity !== null) {
                $stockRow = \App\Models\InventoryStock::firstOrCreate(
                    ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => null],
                    [
                        'quantity' => (float) $product->stock_quantity,
                        'min_stock_level' => (float) ($product->low_stock_threshold ?? 0),
                        'avg_purchase_price' => (float) ($product->cost_price ?? 0),
                        'last_purchase_price' => (float) ($product->cost_price ?? 0),
                    ]
                );
                if ($stockRow->wasRecentlyCreated && $product->stock_quantity > 0) {
                    \App\Models\InventoryMovement::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'type' => \App\Models\InventoryMovement::TYPE_OPENING,
                        'quantity' => (float) $product->stock_quantity,
                        'balance_after' => (float) $product->stock_quantity,
                        'reference_type' => 'product_create',
                        'notes' => 'Opening stock from product creation',
                        'created_by' => auth('pos')->id(),
                    ]);
                }
            }

            // Prefer JSON payload (robust to Alpine template-nesting); fall back to array form fields.
            $ingredientRows = [];
            if ($request->filled('ingredients_json')) {
                $decoded = json_decode((string) $request->input('ingredients_json'), true);
                if (is_array($decoded)) $ingredientRows = $decoded;
            } elseif ($request->has('ingredients') && is_array($request->input('ingredients'))) {
                $ingredientRows = $request->input('ingredients');
            }

            if (!empty($ingredientRows)) {
                foreach ($ingredientRows as $row) {
                    if (!is_array($row)) continue;
                    $qty = $row['quantity_needed'] ?? null;
                    if ($qty === null || $qty === '' || !is_numeric($qty) || (float)$qty <= 0) {
                        // Only count as skipped if the row had any meaningful intent
                        if (!empty($row['ingredient_id']) || !empty($row['new_name'])) $recipeSkipped++;
                        continue;
                    }

                    $ingredient = null;
                    if (!empty($row['ingredient_id'])) {
                        $ingredient = \App\Models\Ingredient::where('company_id', $companyId)
                            ->where('id', $row['ingredient_id'])->first();
                    } elseif (!empty($row['new_name']) && !empty($row['new_unit'])) {
                        $name = trim($row['new_name']);
                        $unit = trim($row['new_unit']);
                        // Reuse if same name+unit already exists (case-insensitive) to avoid dupes
                        $ingredient = \App\Models\Ingredient::where('company_id', $companyId)
                            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                            ->where('unit', $unit)
                            ->first();
                        if (!$ingredient) {
                            $ingredient = \App\Models\Ingredient::create([
                                'company_id' => $companyId,
                                'name' => $name,
                                'unit' => $unit,
                                'cost_per_unit' => isset($row['new_cost']) && is_numeric($row['new_cost']) ? (float)$row['new_cost'] : 0,
                                'current_stock' => 0,
                                'min_stock_level' => 0,
                                'is_active' => true,
                            ]);
                        }
                    }
                    if (!$ingredient) { $recipeSkipped++; continue; }

                    // Avoid duplicate (product, ingredient) pair
                    $exists = \App\Models\ProductRecipe::where('company_id', $companyId)
                        ->where('product_id', $product->id)
                        ->where('ingredient_id', $ingredient->id)
                        ->exists();
                    if ($exists) { $recipeSkipped++; continue; }

                    \App\Models\ProductRecipe::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'ingredient_id' => $ingredient->id,
                        'quantity_needed' => (float)$qty,
                    ]);
                    $recipeAdded++;
                }
            }
            return $product;
        });

        // Auto-fetch image ONLY if cashier explicitly chose image_mode=auto.
        // Default (none) leaves the image field blank so the list shows
        // a name-only row — exactly what the user asked for.
        if (!$imageName && $request->name && $request->input('image_mode') === 'auto') {
            try {
                $autoImage = \App\Services\ProductImageService::fetchForProduct($request->name, $companyId);
                if ($autoImage) {
                    $product->update(['image' => $autoImage]);
                }
            } catch (\Exception $e) {}
        }

        // Build feedback message with recipe context so cashier sees exactly what landed
        $msg = __('pos.product_added_success');
        if ($recipeAdded > 0) {
            $msg .= __('pos.product_recipe_linked', ['count' => $recipeAdded]);
        }
        if ($recipeSkipped > 0) {
            $msg .= __('pos.product_recipe_skipped', ['count' => $recipeSkipped]);
        }
        return back()->with('success', $msg);
    }

    // Sample rows shown in the blank template. importProducts() silently skips a row
    // that still matches one of these EXACTLY (name+price+sku) so an untouched sample
    // never becomes a real product in the shop's list.
    private const IMPORT_SAMPLE_ROWS = [
        ['Chicken Biryani', 450.0, 'CB-001'],
        ['Pepsi 500ml', 120.0, 'PEP-500'],
        ['Naan', 30.0, 'NAN-001'],
    ];

    public function downloadProductTemplate()
    {
        $companyId = app('currentCompanyId');
        $existingProducts = PosProduct::where('company_id', $companyId)->orderBy('name')->get();

        // Real .xlsx (not CSV) — shopkeepers edit in Excel and upload the SAME file
        // back. The old CSV round-trip mangled long barcodes into scientific notation
        // (8.9E+12) and Excel's "save as .xlsx" default made uploads fail (Pizza Master).
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        $headers = ['Name', 'Price', 'Description', 'Category', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)', 'Tax Exempt (Yes/No)'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E9D5FF');
        foreach (['A' => 32, 'B' => 10, 'C' => 32, 'D' => 16, 'E' => 14, 'F' => 18, 'G' => 11, 'H' => 12, 'I' => 18] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        // SKU + Barcode columns forced to TEXT so Excel never converts long codes
        // to scientific notation or strips leading zeros.
        $sheet->getStyle('E:F')->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

        $rowNum = 2;
        if ($existingProducts->isEmpty()) {
            $samples = [
                ['Chicken Biryani', 450, 'Full plate biryani with raita', 'Food', 'CB-001', '8901234567890', 16, 'NOS', 'No'],
                ['Pepsi 500ml', 120, 'Cold drink bottle', 'Beverages', 'PEP-500', '8901234567891', 5, 'NOS', 'No'],
                ['Naan', 30, 'Tandoori naan', 'Food', 'NAN-001', '', 0, 'NOS', 'Yes'],
            ];
            foreach ($samples as $s) {
                $this->writeProductRow($sheet, $rowNum++, $s);
            }
        } else {
            foreach ($existingProducts as $p) {
                $this->writeProductRow($sheet, $rowNum++, [
                    $p->name,
                    (float) $p->price,
                    $p->description ?? '',
                    $p->category ?? '',
                    $p->sku ?? '',
                    $p->barcode ?? '',
                    (float) ($p->tax_rate ?? 0),
                    $p->uom ?? 'NOS',
                    !empty($p->is_tax_exempt) ? 'Yes' : 'No',
                ]);
            }
        }

        $filename = $existingProducts->isEmpty() ? 'pos_products_template.xlsx' : 'pos_products_export.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function writeProductRow($sheet, int $rowNum, array $vals): void
    {
        // A..I = Name, Price, Description, Category, SKU, Barcode, Tax %, UOM, Tax Exempt.
        // SKU/Barcode written as EXPLICIT strings (Excel would otherwise turn
        // 8901234567890 into 8.90123E+12 the moment the file is opened).
        $sheet->setCellValue('A' . $rowNum, $vals[0]);
        $sheet->setCellValue('B' . $rowNum, $vals[1]);
        $sheet->setCellValue('C' . $rowNum, $vals[2]);
        $sheet->setCellValue('D' . $rowNum, $vals[3]);
        $sheet->setCellValueExplicit('E' . $rowNum, (string) $vals[4], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('F' . $rowNum, (string) $vals[5], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('G' . $rowNum, $vals[6]);
        $sheet->setCellValue('H' . $rowNum, $vals[7]);
        $sheet->setCellValue('I' . $rowNum, $vals[8] ?? 'No');
    }

    public function importProducts(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt,xlsx,xls|max:5120',
        ], [
            'csv_file.mimes' => 'File Excel (.xlsx) ya CSV honi chahiye.',
            'csv_file.max' => 'File 5 MB se choti honi chahiye.',
        ]);

        $file = $request->file('csv_file');
        $ext = strtolower($file->getClientOriginalExtension());

        try {
            $rows = in_array($ext, ['xlsx', 'xls'], true)
                ? $this->readImportRowsExcel($file->getRealPath())
                : $this->readImportRowsCsv($file->getRealPath());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('POS product import parse failed: ' . $e->getMessage());
            return back()->with('error', __('pos.file_unreadable'));
        }

        if (count($rows) < 2) {
            return back()->with('error', __('pos.file_empty_rows'));
        }
        if (count($rows) > 5001) {
            return back()->with('error', __('pos.file_max_products'));
        }

        $header = array_map(function ($h) {
            return strtolower(trim(preg_replace('/[\x{FEFF}]/u', '', (string) $h)));
        }, $rows[0]);

        $nameIdx = $this->findColumn($header, ['name', 'product name', 'product', 'item name', 'item']);
        $priceIdx = $this->findColumn($header, ['price', 'sale price', 'rate', 'unit price', 'price (rs)', 'price rs']);

        if ($nameIdx === false || $priceIdx === false) {
            return back()->with('error', __('pos.file_missing_name_price'));
        }

        $descIdx = $this->findColumn($header, ['description', 'details']);
        $catIdx = $this->findColumn($header, ['category', 'group']);
        $skuIdx = $this->findColumn($header, ['sku', 'code', 'item code', 'product code']);
        $barcodeIdx = $this->findColumn($header, ['barcode', 'bar code', 'ean']);
        $taxIdx = $this->findColumn($header, ['tax rate %', 'tax rate', 'tax_rate', 'tax', 'tax %']);
        $uomIdx = $this->findColumn($header, ['unit (uom)', 'unit', 'uom']);
        // Tax Exempt column (owner request Jul 2026): Yes/No round-trip so bulk
        // exempting via Excel works. Older files without the column keep the
        // existing flag untouched.
        $exemptIdx = $this->findColumn($header, ['tax exempt (yes/no)', 'tax exempt', 'exempt (yes/no)', 'exempt', 'tax_exempt', 'is_tax_exempt']);

        // Preload the whole catalog ONCE (bounded per company) — match precedence
        // barcode → SKU → name. Maps updated after each create so a duplicate row
        // in the same file updates instead of double-creating.
        $catalog = PosProduct::where('company_id', $companyId)->get();
        $byBarcode = []; $bySku = []; $byName = [];
        foreach ($catalog as $p) {
            if (trim((string) $p->barcode) !== '') $byBarcode[strtolower(trim($p->barcode))] = $p;
            if (trim((string) $p->sku) !== '') $bySku[strtolower(trim($p->sku))] = $p;
            $byName[strtolower(trim($p->name))] = $p;
        }

        $added = 0; $updated = 0; $skipped = 0; $samplesSkipped = 0;
        $errors = [];

        for ($i = 1; $i < count($rows); $i++) {
            $data = $rows[$i];
            $rowNo = $i + 1;

            $name = trim((string) ($data[$nameIdx] ?? ''));
            $priceRaw = $data[$priceIdx] ?? '';
            $rowEmpty = true;
            foreach ($data as $cell) { if (trim((string) $cell) !== '') { $rowEmpty = false; break; } }
            if ($rowEmpty) continue;

            if ($name === '') { $errors[] = "Row {$rowNo}: naam khali hai"; $skipped++; continue; }

            $price = $this->cleanImportNumber($priceRaw);
            if ($price === null || $price < 0) {
                $errors[] = "Row {$rowNo}: '{$name}' ki price samajh nahi aayi (" . trim((string) $priceRaw) . ")";
                $skipped++;
                continue;
            }

            $sku = $skuIdx !== false ? $this->cleanImportCode($data[$skuIdx] ?? null) : null;
            $barcode = $barcodeIdx !== false ? $this->cleanImportCode($data[$barcodeIdx] ?? null) : null;
            $desc = $descIdx !== false ? trim((string) ($data[$descIdx] ?? '')) : '';
            $cat = $catIdx !== false ? trim((string) ($data[$catIdx] ?? '')) : '';
            $tax = $taxIdx !== false ? $this->cleanImportNumber($data[$taxIdx] ?? '') : null;
            $uom = $uomIdx !== false ? strtoupper(trim((string) ($data[$uomIdx] ?? ''))) : '';

            // Exempt cell: Yes/No (tolerant); blank = leave existing flag as-is
            // (new products default No). Unrecognized value → clear warning, not
            // a silent ignore.
            $exempt = null;
            if ($exemptIdx !== false) {
                $exRaw = strtolower(trim((string) ($data[$exemptIdx] ?? '')));
                if ($exRaw !== '') {
                    if (in_array($exRaw, ['yes', 'y', '1', 'true', 'haan', 'han', 'exempt'], true)) {
                        $exempt = true;
                    } elseif (in_array($exRaw, ['no', 'n', '0', 'false', 'nahi'], true)) {
                        $exempt = false;
                    } else {
                        $errors[] = "Row {$rowNo}: '{$name}' ke Tax Exempt column mein '" . trim((string) ($data[$exemptIdx] ?? '')) . "' samajh nahi aaya — Yes ya No likhein (value ignore hui)";
                    }
                }
            }
            // Tax Rate cell must be a number; 'exempt' written there is understood
            // (flag ON, rate 0), anything else non-numeric gets a clear warning
            // instead of the old silent ignore.
            if ($taxIdx !== false && $tax === null) {
                $taxRaw = trim((string) ($data[$taxIdx] ?? ''));
                if ($taxRaw !== '') {
                    if (strcasecmp($taxRaw, 'exempt') === 0) {
                        $exempt = $exempt ?? true;
                        $tax = 0.0;
                    } else {
                        $errors[] = "Row {$rowNo}: '{$name}' ka Tax Rate '{$taxRaw}' samajh nahi aaya — number likhein (masalan 16), exempt ke liye Tax Exempt column mein Yes likhein";
                    }
                }
            }

            // Untouched template sample rows never become real products.
            foreach (self::IMPORT_SAMPLE_ROWS as $s) {
                if (strcasecmp($name, $s[0]) === 0 && abs($price - $s[1]) < 0.001 && strcasecmp((string) $sku, $s[2]) === 0) {
                    $samplesSkipped++;
                    continue 2;
                }
            }

            $existing = null;
            if ($barcode !== null && isset($byBarcode[strtolower($barcode)])) $existing = $byBarcode[strtolower($barcode)];
            if (!$existing && $sku !== null && isset($bySku[strtolower($sku)])) $existing = $bySku[strtolower($sku)];
            if (!$existing && isset($byName[strtolower($name)])) $existing = $byName[strtolower($name)];

            if ($existing) {
                $existing->update([
                    'name' => $name,
                    'price' => $price,
                    'description' => $desc !== '' ? $desc : $existing->description,
                    'category' => $cat !== '' ? $cat : $existing->category,
                    'sku' => $sku !== null ? $sku : $existing->sku,
                    'barcode' => $barcode !== null ? $barcode : $existing->barcode,
                    'tax_rate' => $tax !== null ? $tax : $existing->tax_rate,
                    'uom' => $uom !== '' ? $uom : $existing->uom,
                    'is_tax_exempt' => $exempt !== null ? $exempt : (bool) $existing->is_tax_exempt,
                ]);
                $updated++;
                $product = $existing;
            } else {
                $product = PosProduct::create([
                    'company_id' => $companyId,
                    'name' => $name,
                    'price' => $price,
                    'show_on_sale' => true, // explicit — never trust the DB default (prod drift)
                    'description' => $desc !== '' ? $desc : null,
                    'category' => $cat !== '' ? $cat : null,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'tax_rate' => $tax !== null ? $tax : 0,
                    'uom' => $uom !== '' ? $uom : 'NOS',
                    'is_tax_exempt' => $exempt === true,
                    'is_active' => true,
                ]);
                $added++;
            }

            // Keep maps fresh so later rows in the same file match this product.
            if ($barcode !== null) $byBarcode[strtolower($barcode)] = $product;
            if ($sku !== null) $bySku[strtolower($sku)] = $product;
            $byName[strtolower($name)] = $product;
        }

        $parts = [];
        if ($added > 0) $parts[] = __('pos.import_new_products_added', ['count' => $added]);
        if ($updated > 0) $parts[] = __('pos.import_updated', ['count' => $updated]);
        if ($samplesSkipped > 0) $parts[] = __('pos.import_sample_rows_skipped', ['count' => $samplesSkipped]);
        if ($skipped > 0) $parts[] = __('pos.import_rows_skipped', ['count' => $skipped]);
        $msg = $parts ? implode(', ', $parts) . '.' : __('pos.import_no_rows');
        if (!empty($errors)) $msg .= __('pos.import_issues', ['issues' => implode('; ', array_slice($errors, 0, 5))]) . (count($errors) > 5 ? __('pos.import_more_suffix', ['count' => count($errors) - 5]) : '');

        if ($added === 0 && $updated === 0) {
            return back()->with('error', $msg);
        }
        return back()->with('success', $msg);
    }

    private function readImportRowsExcel(string $path): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // Row-cap BEFORE materializing the sheet — a 5MB xlsx can hold hundreds of
        // thousands of rows (zip compression); toArray() on that would OOM shared
        // cPanel PHP before the post-parse count check ever ran.
        if ($sheet->getHighestDataRow() > 5001) {
            $spreadsheet->disconnectWorksheets();
            return array_fill(0, 5002, []); // triggers the friendly >5000 error upstream
        }

        // calculateFormulas=true, formatData=false (raw values), returnCellRef=false
        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();
        return $rows;
    }

    private function readImportRowsCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) throw new \RuntimeException('Could not open file');

        // Excel (regional settings) sometimes saves CSV with ; or TAB — auto-detect
        // from the header line instead of assuming comma.
        $firstLine = fgets($handle) ?: '';
        $delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delims);
        $delim = array_key_first($delims);
        if ($delims[$delim] === 0) $delim = ',';
        rewind($handle);

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delim)) !== false) {
            $rows[] = $data;
            if (count($rows) > 5002) break;
        }
        fclose($handle);
        return $rows;
    }

    // "Rs 1,200", "1200.50", "16%" → float; anything non-numeric → null.
    private function cleanImportNumber($raw): ?float
    {
        if (is_int($raw) || is_float($raw)) return (float) $raw;
        $s = trim((string) $raw);
        if ($s === '') return null;
        $s = str_ireplace(['rs.', 'rs', 'pkr', '%'], '', $s);
        $s = str_replace([',', ' '], '', $s);
        if (!is_numeric($s)) return null;
        return (float) $s;
    }

    // SKU/Barcode cleaner: Excel numeric cells arrive as floats (8901234567890.0)
    // and CSV round-trips arrive as scientific notation ("8.90123E+12") — both are
    // restored to plain digit strings. Empty → null (never overwrite with blank).
    private function cleanImportCode($raw): ?string
    {
        if ($raw === null) return null;
        if (is_int($raw) || is_float($raw)) return sprintf('%.0f', (float) $raw);
        $s = trim((string) $raw);
        if ($s === '') return null;
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $s)) return sprintf('%.0f', (float) $s);
        if (preg_match('/^\d+\.0+$/', $s)) return preg_replace('/\.0+$/', '', $s);
        return $s;
    }

    private function findColumn(array $header, array $names): int|false
    {
        foreach ($names as $name) {
            $idx = array_search($name, $header);
            if ($idx !== false) return $idx;
        }
        return false;
    }

    public function updateProduct(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'category' => 'nullable|string|max:100',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'uom' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'stock_quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'drug_type' => 'nullable|string|max:50',
            'unit_type' => 'nullable|string|max:20',
            'size' => 'nullable|string|max:30',
            'color' => 'nullable|string|max:50',
            'season' => 'nullable|string|max:30',
            'serial_number' => 'nullable|string|max:100',
            'warranty_months' => 'nullable|integer|min:0',
            'imei' => 'nullable|string|max:20',
            'bulk_discount_qty' => 'nullable|integer|min:0',
            'bulk_discount_pct' => 'nullable|numeric|min:0|max:100',
            'service_duration' => 'nullable|integer|min:0',
            'staff_assignment' => 'nullable|string|max:100',
            'vehicle_make' => 'nullable|string|max:50',
            'vehicle_model' => 'nullable|string|max:50',
            'part_number' => 'nullable|string|max:100',
            'box_type' => 'nullable|string|max:50',
        ]);

        $isExempt = $request->has('is_tax_exempt');
        $data = array_merge(
            $request->only(['name', 'description', 'price', 'category', 'sku', 'barcode', 'uom']),
            [
                'cost_price' => $request->filled('cost_price') ? $request->cost_price : 0,
                'stock_quantity' => $request->filled('stock_quantity') ? (int) $request->stock_quantity : null,
                'low_stock_threshold' => $request->filled('low_stock_threshold') ? (int) $request->low_stock_threshold : ($product->low_stock_threshold ?? 10),
                // Backend hardening: exempt MUST force tax_rate=0; otherwise honor submitted value (or keep current if absent)
                'tax_rate' => $isExempt ? 0 : ($request->has('tax_rate') ? $request->tax_rate : $product->tax_rate),
                'is_tax_exempt' => $isExempt,
                'show_on_sale' => $request->has('show_on_sale'),
                'prescription_required' => $request->has('prescription_required'),
                'weight_based' => $request->has('weight_based'),
                'custom_order' => $request->has('custom_order'),
            ]
        );

        $categoryExtraFields = [
            'batch_number', 'expiry_date', 'drug_type', 'unit_type',
            'size', 'color', 'season', 'serial_number', 'warranty_months', 'imei',
            'bulk_discount_qty', 'bulk_discount_pct', 'service_duration', 'staff_assignment',
            'vehicle_make', 'vehicle_model', 'part_number', 'box_type',
        ];
        foreach ($categoryExtraFields as $field) {
            $data[$field] = $request->filled($field) ? $request->$field : null;
        }

        if ($request->has('remove_image') && $request->remove_image === '1') {
            if ($product->image && \Illuminate\Support\Facades\Storage::disk('public')->exists('products/' . $product->image)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete('products/' . $product->image);
            }
            $data['image'] = null;
        }

        if ($request->hasFile('image')) {
            if ($product->image && \Illuminate\Support\Facades\Storage::disk('public')->exists('products/' . $product->image)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete('products/' . $product->image);
            }
            $imageName = $companyId . '_' . time() . '_' . uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
            $request->file('image')->storeAs('products', $imageName, 'public');
            $data['image'] = $imageName;
        }

        // Inventory mirror sync on edit: apply an explicit stock_quantity change
        // as a 'set' adjustment on the inventory module (movement + audit row),
        // never a blind overwrite. Runs only when the module is ON and the
        // submitted value actually differs from the current one.
        $companyRow = \App\Models\Company::find($companyId);
        $oldStockQty = $product->stock_quantity;
        $newStockQty = $data['stock_quantity'];
        if (
            $companyRow && $companyRow->inventory_enabled
            && $newStockQty !== null && (int) $newStockQty !== (int) ($oldStockQty ?? PHP_INT_MIN)
        ) {
            \DB::transaction(function () use ($companyId, $product, $newStockQty) {
                $stockRow = \App\Models\InventoryStock::firstOrCreate(
                    ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => null],
                    ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
                );
                $prevQty = (float) $stockRow->quantity;
                $setQty = (float) $newStockQty;
                if ($prevQty !== $setQty) {
                    $stockRow->update(['quantity' => $setQty]);
                    \App\Models\InventoryMovement::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'type' => $setQty > $prevQty
                            ? \App\Models\InventoryMovement::TYPE_ADJUSTMENT_IN
                            : \App\Models\InventoryMovement::TYPE_ADJUSTMENT_OUT,
                        'quantity' => abs($setQty - $prevQty),
                        'balance_after' => $setQty,
                        'reference_type' => 'adjustment',
                        'notes' => 'Stock set via product edit',
                        'created_by' => auth('pos')->id(),
                    ]);
                    \App\Models\InventoryAdjustment::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'type' => 'set',
                        'quantity' => $setQty,
                        'previous_quantity' => $prevQty,
                        'new_quantity' => $setQty,
                        'reason' => 'Product edit',
                        'notes' => null,
                        'created_by' => auth('pos')->id(),
                    ]);
                }
            });
        }

        $product->update($data);

        // Auto-fetch image ONLY if cashier explicitly chose image_mode=auto on edit.
        // Other modes (keep / upload / remove) are already handled above.
        if ($request->input('image_mode') === 'auto' && empty($data['image'] ?? null) && $product->name) {
            try {
                $autoImage = \App\Services\ProductImageService::fetchForProduct($product->name, $companyId);
                if ($autoImage) {
                    $product->update(['image' => $autoImage]);
                }
            } catch (\Exception $e) {}
        }

        return back()->with('success', __('pos.product_updated_success'));
    }

    public function deleteProduct($id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);
        $product->delete();
        // Inventory mirror cleanup — otherwise deleted products linger in the
        // module's tracked/out-of-stock counts forever (movement history stays).
        \App\Models\InventoryStock::where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->delete();
        return back()->with('success', __('pos.product_deleted_success'));
    }

    public function toggleProduct($id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);
        $product->update(['is_active' => !$product->is_active]);
        return back()->with('success', $product->is_active ? __('pos.product_activated') : __('pos.product_deactivated'));
    }

    public function toggleProductSale($id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);
        $product->update(['show_on_sale' => !$product->show_on_sale]);
        return back()->with('success', $product->show_on_sale ? __('pos.product_visible_on_sale') : __('pos.product_hidden_from_sale'));
    }

    /**
     * Bulk hide/show ALL products (or one category) on the sale-screen grid.
     * STRICT allowlist: only pos_admin / pos_manager / company_admin (isPosAdmin)
     * may run this — every other role (cashier, kitchen, waiter, rider, viewers)
     * gets a true 403. Uses the existing show_on_sale flag, so hidden products
     * stay searchable + billable exactly like single-hide.
     */
    public function bulkToggleSale(Request $request)
    {
        $user = auth('pos')->user();
        // pos_role wins over the base role column: a user assigned pos_cashier is a
        // cashier inside the POS panel even if their base role is company_admin.
        if (!$user || ($user->isPosCashier() ? $user->posCashierBlocked() : !$user->isPosAdmin())) {
            abort(403, 'Only POS administrators can bulk-change sale screen visibility.');
        }
        $request->validate([
            'action' => 'required|in:hide,show',
            'category' => 'nullable|string|max:100',
        ]);
        $companyId = app('currentCompanyId');
        $show = $request->action === 'show';

        // Only flip rows that are actually in the opposite state so the
        // flashed count = products genuinely affected.
        $query = PosProduct::where('company_id', $companyId)
            ->where('show_on_sale', !$show);
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        $count = $query->update(['show_on_sale' => $show]);

        $scope = $request->filled('category') ? __('pos.scope_category_prefix', ['category' => $request->input('category')]) : '';
        $msg = $show
            ? __('pos.products_shown_scope', ['count' => number_format($count), 'scope' => $scope])
            : __('pos.products_hidden_scope', ['count' => number_format($count), 'scope' => $scope]);
        return back()->with('success', $msg);
    }

    /**
     * Bulk actions on selected products (company-scoped).
     * action: activate | deactivate | delete | category (with category_value).
     */
    public function bulkProductAction(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'action' => 'required|string|in:activate,deactivate,delete,category,price,price_percent,exempt_on,exempt_off',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'category_value' => 'nullable|string|max:100',
            // Bulk pricing (owner request Jul 2026): fixed price OR percent change.
            'price_value' => 'nullable|numeric|min:0|max:10000000',
            'percent_value' => 'nullable|numeric|min:-90|max:500',
        ]);

        $query = PosProduct::where('company_id', $companyId)->whereIn('id', $request->ids);
        $count = (clone $query)->count();

        switch ($request->action) {
            case 'activate':
                $query->update(['is_active' => true]);
                $msg = __('pos.products_activated_count', ['count' => $count]);
                break;
            case 'deactivate':
                $query->update(['is_active' => false]);
                $msg = __('pos.products_deactivated_count', ['count' => $count]);
                break;
            case 'category':
                $query->update(['category' => $request->category_value ?: null]);
                $msg = __('pos.products_recategorized_count', ['count' => $count]);
                break;
            case 'price':
                // Fixed price for all selected (small same-price groups).
                if ($request->input('price_value') === null || $request->input('price_value') === '') {
                    return back()->with('error', __('pos.enter_new_price'));
                }
                $newPrice = round((float) $request->input('price_value'), 2);
                $query->update(['price' => $newPrice]);
                $msg = __('pos.products_price_set', ['count' => $count, 'price' => $newPrice]);
                break;
            case 'price_percent':
                // Percent increase/decrease — inflation reprice across a list.
                // Validated numeric (-90..500); SQL-side so 5000 selected rows
                // stay one UPDATE (no per-row loop on shared cPanel PHP).
                $pct = (float) $request->input('percent_value');
                if ($request->input('percent_value') === null || $request->input('percent_value') === '' || abs($pct) < 0.001) {
                    return back()->with('error', __('pos.enter_percent'));
                }
                $factor = sprintf('%.6F', 1 + $pct / 100);
                $query->update(['price' => DB::raw("ROUND(GREATEST(price * {$factor}, 0), 2)")]);
                $msg = __('pos.products_price_updated_pct', ['count' => $count, 'pct' => ($pct > 0 ? "+{$pct}%" : "{$pct}%")]);
                break;
            case 'exempt_on':
                // Flag only — sale math reads is_tax_exempt (tax_rate untouched so
                // switching back OFF restores the product's own rate).
                $query->update(['is_tax_exempt' => true]);
                $msg = __('pos.products_tax_exempt_on', ['count' => $count]);
                break;
            case 'exempt_off':
                $query->update(['is_tax_exempt' => false]);
                $msg = __('pos.products_tax_exempt_off', ['count' => $count]);
                break;
            case 'delete':
                // Clean up images before delete
                foreach ((clone $query)->whereNotNull('image')->pluck('image') as $img) {
                    if ($img && \Illuminate\Support\Facades\Storage::disk('public')->exists('products/' . $img)) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete('products/' . $img);
                    }
                }
                $query->delete();
                $msg = __('pos.products_deleted_count', ['count' => $count]);
                break;
            default:
                $msg = __('pos.no_action_taken');
        }

        return back()->with('success', $msg);
    }

    /**
     * Printable barcode/price label sheet for selected products (or all).
     * Renders A4 grid of labels; client-side JsBarcode draws Code128 from
     * barcode (fallback: sku, then PRA-<id>).
     */
    public function productLabels(Request $request)
    {
        $companyId = app('currentCompanyId');
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));
        $query = PosProduct::where('company_id', $companyId)->orderBy('name');
        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }
        $products = $query->get();
        $company = \App\Models\Company::find($companyId);
        return view('pos.product-labels', compact('products', 'company'));
    }

    public function customers(Request $request)
    {
        $companyId = app('currentCompanyId');
        // ZFC (Aug 2026): shops with 10k+ customers froze this page — it rendered
        // EVERY row server-side. Now: server-side search + pagination (100/page).
        // The search box filters on the SERVER so any phone/name is findable
        // regardless of page.
        $q = trim((string) $request->query('q', ''));
        $query = PosCustomer::where('company_id', $companyId);
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                $w->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('cnic', 'like', $like)
                    ->orWhere('ntn', 'like', $like);
            });
        }
        $totalCount = PosCustomer::where('company_id', $companyId)->count();
        $customers = $query->orderBy('name')->paginate(100)->withQueryString();
        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        return view('pos.customers', compact('customers', 'isCashier', 'q', 'totalCount'));
    }

    public function storeCustomer(Request $request)
    {
        $companyId = app('currentCompanyId');
        // Name is OPTIONAL when a phone is given (owner request, Jul 2026):
        // blank name = the phone number doubles as the display name.
        $request->validate([
            'name' => 'nullable|required_without:phone|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'type' => 'required|in:registered,unregistered',
        ]);

        $customer = PosCustomer::create(array_merge($request->only(['name', 'email', 'phone', 'address', 'city', 'ntn', 'cnic', 'type']), [
            'company_id' => $companyId,
            'name' => trim((string) $request->name) !== '' ? trim($request->name) : $request->phone,
        ]));

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'customer' => ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone]]);
        }

        return back()->with('success', __('pos.customer_added_success'));
    }

    public function updateCustomer(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'type' => 'required|in:registered,unregistered',
        ]);

        $customer->update($request->only(['name', 'email', 'phone', 'address', 'city', 'ntn', 'cnic', 'type']));
        return back()->with('success', __('pos.customer_updated_success'));
    }

    public function deleteCustomer($id)
    {
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $customer->delete();
        return back()->with('success', __('pos.customer_deleted_success'));
    }

    public function toggleCustomer($id)
    {
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $customer->update(['is_active' => !$customer->is_active]);
        return back()->with('success', $customer->is_active ? __('pos.customer_activated') : __('pos.customer_deactivated'));
    }

    /**
     * Neutralize CSV formula-injection: cells starting with = + - @ (or a
     * leading tab/CR) are prefixed with a single quote so spreadsheets treat
     * them as text instead of executing them as a formula.
     */
    private function csvSafe($value)
    {
        $s = (string) $value;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * Export the company's POS customers to a CSV (streamed). Live order/spend
     * totals match the history view (customer_id OR phone) without double-counting.
     */
    public function exportCustomers()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customers = PosCustomer::where('company_id', $companyId)->orderBy('name')->get();

        // Aggregate by linked id, then by phone for rows WITHOUT a linked id
        // (so a sale counted by id is never also counted by phone).
        $aggById = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as cnt, SUM(total_amount) as spent')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $aggByPhone = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereNull('customer_id')
            ->whereNotNull('customer_phone')
            ->selectRaw('customer_phone, COUNT(*) as cnt, SUM(total_amount) as spent')
            ->groupBy('customer_phone')
            ->get()
            ->keyBy('customer_phone');

        $filename = 'POS_Customers_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($customers, $aggById, $aggByPhone, $company) {
            $file = fopen('php://output', 'w');
            // UTF-8 BOM so Excel renders Urdu / special chars correctly.
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, [$this->csvSafe('POS Customers — ' . ($company->name ?? ''))]);
            fputcsv($file, ['Generated: ' . now()->format('d M Y H:i')]);
            fputcsv($file, []);
            fputcsv($file, ['Name', 'Phone', 'Email', 'CNIC', 'NTN', 'City', 'Address', 'Type', 'Status', 'Total Orders', 'Total Spent']);
            foreach ($customers as $c) {
                $byId = $aggById[$c->id] ?? null;
                $byPhone = $c->phone ? ($aggByPhone[$c->phone] ?? null) : null;
                $cnt = (int) ($byId->cnt ?? 0) + (int) ($byPhone->cnt ?? 0);
                $spent = (float) ($byId->spent ?? 0) + (float) ($byPhone->spent ?? 0);
                fputcsv($file, [
                    $this->csvSafe($c->name),
                    $this->csvSafe($c->phone),
                    $this->csvSafe($c->email),
                    $this->csvSafe($c->cnic),
                    $this->csvSafe($c->ntn),
                    $this->csvSafe($c->city),
                    $this->csvSafe($c->address),
                    ucfirst($c->type),
                    $c->is_active ? 'Active' : 'Inactive',
                    $cnt,
                    number_format($spent, 2, '.', ''),
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Download a blank CSV template (with one example row) for customer import.
     */
    public function downloadCustomerTemplate()
    {
        $filename = 'POS_Customers_Import_Template.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];
        $callback = function () {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Name', 'Phone', 'Email', 'CNIC', 'NTN', 'City', 'Address', 'Type']);
            fputcsv($file, ['Ahmed Khan', '03001234567', 'ahmed@example.com', '35201-1234567-1', '1234567-8', 'Lahore', '123 Main Street', 'unregistered']);
            fclose($file);
        };
        return response()->stream($callback, 200, $headers);
    }

    /**
     * Import POS customers from an uploaded CSV. Forces company_id, dedupes by
     * phone then CNIC within the company (updates existing), skips invalid rows.
     */
    public function importCustomers(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $companyId = app('currentCompanyId');
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->with('error', __('pos.customer_import_could_not_read'));
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return back()->with('error', __('pos.customer_import_empty'));
        }
        // Strip a UTF-8 BOM that Excel may prepend to the first header cell.
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        $map = [];
        foreach ($header as $i => $col) {
            $key = strtolower(trim(str_replace([' ', '_', '-'], '', (string) $col)));
            if ($key !== '') {
                $map[$key] = $i;
            }
        }

        $get = function ($row, $keys) use ($map) {
            foreach ((array) $keys as $k) {
                if (isset($map[$k]) && isset($row[$map[$k]])) {
                    return trim((string) $row[$map[$k]]);
                }
            }
            return null;
        };

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $rowNum = 1;
        $errors = [];

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                    continue; // wholly blank line
                }

                $name = $get($row, ['name', 'customername', 'fullname']);
                if (empty($name)) {
                    $skipped++;
                    if (count($errors) < 10) $errors[] = "Row {$rowNum}: missing name — skipped.";
                    continue;
                }

                // Truncate per column length so a long cell never throws a
                // QueryException that rolls back the entire import.
                $name = mb_substr($name, 0, 255);
                $phone = ($v = $get($row, ['phone', 'mobile', 'contact', 'phonenumber'])) !== null && $v !== '' ? mb_substr($v, 0, 20) : null;
                $cnic = ($v = $get($row, ['cnic'])) !== null && $v !== '' ? mb_substr($v, 0, 20) : null;
                $email = ($v = $get($row, ['email'])) !== null && $v !== '' ? mb_substr($v, 0, 255) : null;
                $ntn = ($v = $get($row, ['ntn'])) !== null && $v !== '' ? mb_substr($v, 0, 50) : null;
                $city = ($v = $get($row, ['city'])) !== null && $v !== '' ? mb_substr($v, 0, 100) : null;
                $address = ($v = $get($row, ['address'])) !== null && $v !== '' ? mb_substr($v, 0, 500) : null;

                // Only treat type as authoritative when the cell actually has a value,
                // so a partial CSV never silently flips registered → unregistered.
                $typeRaw = $get($row, ['type']);
                $hasType = $typeRaw !== null && trim($typeRaw) !== '';
                $type = strtolower((string) $typeRaw) === 'registered' ? 'registered' : 'unregistered';

                $existing = null;
                if (!empty($phone)) {
                    $existing = PosCustomer::where('company_id', $companyId)->where('phone', $phone)->first();
                }
                if (!$existing && !empty($cnic)) {
                    $existing = PosCustomer::where('company_id', $companyId)->where('cnic', $cnic)->first();
                }

                $fields = [
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'cnic' => $cnic,
                    'ntn' => $ntn,
                    'city' => $city,
                    'address' => $address,
                ];

                if ($existing) {
                    // Non-destructive: only overwrite fields the CSV actually carries,
                    // so blank cells never null out existing data.
                    $updateData = [];
                    foreach ($fields as $k => $val) {
                        if ($val !== null && $val !== '') {
                            $updateData[$k] = $val;
                        }
                    }
                    if ($hasType) {
                        $updateData['type'] = $type;
                    }
                    if (!empty($updateData)) {
                        $existing->update($updateData);
                    }
                    $updated++;
                } else {
                    PosCustomer::create(array_merge($fields, [
                        'type' => $hasType ? $type : 'unregistered',
                        'company_id' => $companyId,
                        'is_active' => true,
                    ]));
                    $imported++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            \Log::error('POS customer import failed', ['company_id' => $companyId, 'error' => $e->getMessage()]);
            return back()->with('error', __('pos.customer_import_failed'));
        }
        fclose($handle);

        $msg = __('pos.customer_import_complete', ['added' => $imported, 'updated' => $updated, 'skipped' => ($skipped ? __('pos.customer_import_skipped_part', ['count' => $skipped]) : '')]);
        return back()->with('success', $msg)->with('import_errors', $errors);
    }

    /**
     * Resolve a customer's completed transactions, matching by id OR phone
     * (POS records customer_phone on walk-in sales even without a linked id).
     */
    private function customerTransactions($companyId, PosCustomer $customer)
    {
        return PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id);
                if (!empty($customer->phone)) {
                    $q->orWhere('customer_phone', $customer->phone);
                }
            })
            ->orderByDesc('created_at');
    }

    /**
     * Full customer purchase history for the history page / CSV / PDF.
     *
     * When the company's "customer spend persist" setting is ON (default), the
     * history additionally includes:
     *   - ARCHIVED local bills (day-close 'save' policy) — via withoutGlobalScope
     *   - spend SNAPSHOTS of deleted local bills (day-close 'delete' policy) —
     *     merged as non-persisted PosTransaction stand-ins carrying the original
     *     figures, flagged is_spend_snapshot so views can annotate them.
     * When OFF, behaviour is the classic live-bills-only view.
     *
     * @return \Illuminate\Support\Collection ordered newest-first
     */
    private function customerHistoryTransactions($companyId, PosCustomer $customer)
    {
        $company = Company::find($companyId);
        $persist = (bool) ($company->pos_customer_spend_persist ?? true);

        $query = $this->customerTransactions($companyId, $customer);
        if ($persist) {
            $query->withoutGlobalScope('hide_archived');
        }
        $transactions = $query->get();

        if ($persist && \Illuminate\Support\Facades\Schema::hasTable('pos_customer_spend_snapshots')) {
            $snapshots = \App\Models\PosCustomerSpendSnapshot::where('company_id', $companyId)
                ->where(function ($q) use ($customer) {
                    $q->where('customer_id', $customer->id);
                    if (!empty($customer->phone)) {
                        $q->orWhere('customer_phone', $customer->phone);
                    }
                })
                ->get()
                ->map(function ($s) {
                    $t = (new PosTransaction)->forceFill([
                        'invoice_number' => $s->invoice_number,
                        'invoice_mode' => 'local',
                        'pra_status' => null,
                        'pra_invoice_number' => null,
                        'payment_method' => $s->payment_method,
                        'subtotal' => $s->subtotal,
                        'discount_amount' => $s->discount_amount,
                        'tax_amount' => $s->tax_amount,
                        'total_amount' => $s->total_amount,
                        'created_at' => $s->sold_at ?? $s->created_at,
                    ]);
                    $t->is_spend_snapshot = true;
                    return $t;
                });

            $transactions = $transactions->concat($snapshots)
                ->sortByDesc(fn ($t) => $t->created_at)
                ->values();
        }

        return $transactions;
    }

    /**
     * Per-customer purchase history page.
     */
    public function customerHistory($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);

        $transactions = $this->customerHistoryTransactions($companyId, $customer);
        $totalSpent = $transactions->sum('total_amount');
        $totalOrders = $transactions->count();
        $avgOrder = $totalOrders > 0 ? $totalSpent / $totalOrders : 0;
        $lastOrder = $transactions->first();

        return view('pos.customer-history', compact('company', 'customer', 'transactions', 'totalSpent', 'totalOrders', 'avgOrder', 'lastOrder'));
    }

    /**
     * Download a single customer's purchase history as CSV.
     */
    public function exportCustomerHistory($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $transactions = $this->customerHistoryTransactions($companyId, $customer);

        $filename = 'Customer_History_' . preg_replace('/[^A-Za-z0-9]+/', '_', $customer->name) . '_' . now()->format('Ymd') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($transactions, $customer, $company) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, [$this->csvSafe('Customer Purchase History — ' . ($company->name ?? ''))]);
            fputcsv($file, [$this->csvSafe('Customer: ' . $customer->name), 'Phone: ' . ($customer->phone ?: '—')]);
            fputcsv($file, ['Generated: ' . now()->format('d M Y H:i')]);
            fputcsv($file, []);
            fputcsv($file, ['Date', 'Invoice #', 'Mode', 'Payment', 'Subtotal', 'Discount', 'Tax', 'Total']);
            foreach ($transactions as $t) {
                fputcsv($file, [
                    $t->created_at->format('d M Y H:i'),
                    $this->csvSafe($t->invoice_number),
                    $t->isLocalBill() ? (($t->is_spend_snapshot ?? false) ? 'Local (record)' : 'Local') : 'PRA',
                    ucwords(str_replace('_', ' ', (string) $t->payment_method)),
                    number_format($t->subtotal, 2, '.', ''),
                    number_format($t->discount_amount, 2, '.', ''),
                    number_format($t->tax_amount, 2, '.', ''),
                    number_format($t->total_amount, 2, '.', ''),
                ]);
            }
            fputcsv($file, []);
            fputcsv($file, ['', '', '', 'TOTAL', '', '', '', number_format($transactions->sum('total_amount'), 2, '.', '')]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Download a single customer's purchase history as a PDF.
     */
    public function customerHistoryPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $transactions = $this->customerHistoryTransactions($companyId, $customer);
        $totalSpent = $transactions->sum('total_amount');
        $totalOrders = $transactions->count();

        $filename = 'Customer_History_' . preg_replace('/[^A-Za-z0-9]+/', '_', $customer->name) . '_' . now()->format('Ymd') . '.pdf';
        return $this->renderReportPdf(
            'pos.customer-history-pdf',
            compact('company', 'customer', 'transactions', 'totalSpent', 'totalOrders'),
            $filename
        );
    }

    public function getLastOrder(Request $request)
    {
        $companyId = app('currentCompanyId');
        $userId = auth()->id();

        $last = \App\Models\PosTransaction::where('company_id', $companyId)
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderByDesc('id')
            ->first();

        if (!$last) {
            return response()->json(['success' => false, 'message' => __('pos.no_previous_order')]);
        }

        $items = \DB::table('pos_transaction_items')
            ->where('transaction_id', $last->id)
            ->get()
            ->map(function ($it) {
                return [
                    'type' => $it->item_type ?? 'product',
                    'item_id' => $it->item_id ?? '',
                    'name' => $it->item_name ?? '',
                    'quantity' => (float) ($it->quantity ?? 1),
                    'unit_price' => (float) ($it->unit_price ?? 0),
                    'is_tax_exempt' => (bool) ($it->is_tax_exempt ?? false),
                    '_isNew' => false,
                ];
            })->values();

        return response()->json([
            'success' => true,
            'invoice_number' => $last->invoice_number,
            'customer_name' => $last->customer_name,
            'customer_phone' => $last->customer_phone,
            'payment_method' => $last->payment_method,
            'items' => $items,
        ]);
    }

    public function saveDraft(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'draft_data' => 'required|array',
        ]);

        $draftId = $request->input('draft_id');
        $draftData = $request->input('draft_data');

        if ($draftId) {
            $draft = PosTransaction::where('company_id', $companyId)
                ->where('id', $draftId)
                ->where('status', 'draft')
                ->first();

            if ($draft) {
                if ($draft->isLocked() && $draft->locked_by_terminal_id != $request->input('terminal_id')) {
                    return response()->json(['success' => false, 'message' => __('pos.invoice_being_edited_terminal_generic')], 423);
                }

                $draft->update([
                    'customer_name' => $draftData['customer_name'] ?? null,
                    'customer_phone' => $draftData['customer_phone'] ?? null,
                    'terminal_id' => $draftData['terminal_id'] ?? null,
                    'subtotal' => $draftData['subtotal'] ?? 0,
                    'discount_type' => $draftData['discount_type'] ?? 'percentage',
                    'discount_value' => $draftData['discount_value'] ?? 0,
                    'discount_amount' => $draftData['discount_amount'] ?? 0,
                    'tax_rate' => $draftData['tax_rate'] ?? 0,
                    'tax_amount' => $draftData['tax_amount'] ?? 0,
                    'total_amount' => $draftData['total_amount'] ?? 0,
                    'payment_method' => $draftData['payment_method'] ?? 'cash',
                ]);

                $draft->items()->delete();
                foreach (($draftData['items'] ?? []) as $item) {
                    PosTransactionItem::create([
                        'transaction_id' => $draft->id,
                        'item_type' => $item['type'] ?? 'product',
                        'item_id' => $item['item_id'] ?? null,
                        'item_name' => $item['name'] ?? '',
                        'quantity' => $item['quantity'] ?? 1,
                        'unit_price' => $item['unit_price'] ?? 0,
                        'subtotal' => round(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2),
                    ]);
                }

                return response()->json(['success' => true, 'draft_id' => $draft->id]);
            }
        }

        DB::beginTransaction();
        try {
            $company = Company::find($companyId);
            // Per-cashier toggle (owner rule Jul 2026): the drafting user's own switch.
            $praEnabled = (bool) auth('pos')->user()?->praReportingEnabled($company);
            $invoiceMode = $praEnabled ? 'pra' : 'local';
            $invoiceNumber = $invoiceMode === 'local'
                ? $this->generateLocalInvoiceNumber($companyId)
                : $this->generateInvoiceNumber($companyId);

            $draft = PosTransaction::create([
                'company_id' => $companyId,
                'terminal_id' => $draftData['terminal_id'] ?? null,
                'invoice_number' => $invoiceNumber,
                'invoice_mode' => $invoiceMode,
                'customer_name' => $draftData['customer_name'] ?? null,
                'customer_phone' => $draftData['customer_phone'] ?? null,
                'subtotal' => $draftData['subtotal'] ?? 0,
                'discount_type' => $draftData['discount_type'] ?? 'percentage',
                'discount_value' => $draftData['discount_value'] ?? 0,
                'discount_amount' => $draftData['discount_amount'] ?? 0,
                'tax_rate' => $draftData['tax_rate'] ?? 0,
                'tax_amount' => $draftData['tax_amount'] ?? 0,
                'total_amount' => $draftData['total_amount'] ?? 0,
                'payment_method' => $draftData['payment_method'] ?? 'cash',
                'status' => 'draft',
                'pra_status' => $praEnabled ? 'pending' : 'local',
                'created_by' => auth('pos')->id(),
                'locked_by_terminal_id' => $draftData['terminal_id'] ?? null,
                'lock_time' => now(),
            ]);

            foreach (($draftData['items'] ?? []) as $item) {
                PosTransactionItem::create([
                    'transaction_id' => $draft->id,
                    'item_type' => $item['type'] ?? 'product',
                    'item_id' => $item['item_id'] ?? null,
                    'item_name' => $item['name'] ?? '',
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'] ?? 0,
                    'subtotal' => round(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2),
                ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'draft_id' => $draft->id]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getDrafts()
    {
        $companyId = app('currentCompanyId');

        $drafts = PosTransaction::where('company_id', $companyId)
            ->where('status', 'draft')
            ->with('items')
            ->orderBy('updated_at', 'desc')
            ->take(10)
            ->get();

        return response()->json(['drafts' => $drafts]);
    }

    public function deleteDraft($id)
    {
        $companyId = app('currentCompanyId');
        $draft = PosTransaction::where('company_id', $companyId)
            ->where('id', $id)
            ->where('status', 'draft')
            ->first();

        if (!$draft) {
            return response()->json(['success' => false, 'message' => __('pos.draft_not_found')], 404);
        }

        $draft->items()->delete();
        $draft->payments()->delete();
        $draft->delete();

        return response()->json(['success' => true]);
    }

    public function lockInvoice(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $terminalId = $request->input('terminal_id');

        if (!$terminalId) {
            return response()->json(['success' => false, 'message' => __('pos.terminal_id_required')], 400);
        }

        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->isLocked() && $transaction->locked_by_terminal_id != $terminalId) {
            $lockedTerminal = PosTerminal::find($transaction->locked_by_terminal_id);
            $terminalName = $lockedTerminal ? $lockedTerminal->terminal_name : 'Unknown';
            return response()->json([
                'success' => false,
                'message' => __('pos.invoice_being_edited_terminal', ['terminal' => $terminalName]),
                'locked_by' => $terminalName,
                'lock_time' => $transaction->lock_time?->toISOString(),
            ], 423);
        }

        $transaction->acquireLock((int) $terminalId);
        return response()->json(['success' => true]);
    }

    public function unlockInvoice($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);
        $transaction->releaseLock();
        return response()->json(['success' => true]);
    }

    private function resolveItemExemptions(array $requestItems, int $companyId): array
    {
        $resolved = [];
        foreach ($requestItems as $item) {
            $itemType = $item['type'] ?? 'product';
            $itemId = $item['item_id'] ?? null;
            $itemName = trim($item['name'] ?? '');
            $itemPrice = (float) ($item['unit_price'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            // Cashier's per-line T-toggle is authoritative — frontend already initializes
            // is_tax_exempt from product master default when item is added to cart, then
            // user may flip it via T-key. We MUST honor that override here.
            $isExempt = !empty($item['is_tax_exempt']);

            $dealSnapshot = null;
            if ($itemId) {
                if ($itemType === 'product') {
                    $obj = PosProduct::where('company_id', $companyId)->where('id', $itemId)->first();
                    if (!$obj) {
                        $itemId = null;
                    }
                    // NOTE: Do NOT overwrite $isExempt from $obj->is_tax_exempt here.
                    // Cart payload already reflects user's intent (master default OR T-toggle override).
                } elseif ($itemType === 'deal') {
                    // Deals (Jul 2026): MANDATORY explicit branch — without it a deal's
                    // item_id would be probed against pos_services below. Server price is
                    // ENFORCED (promo price is server-defined; closes the tamper hole),
                    // and a frozen component snapshot [{product_id,name,qty}] is captured
                    // now so receipts + inventory restore stay correct even if the deal
                    // is later edited or deleted. Unresolved → item_id null, client price
                    // (consistent with services).
                    $deal = PosDeal::where('company_id', $companyId)->where('id', $itemId)->with('items')->first();
                    if ($deal) {
                        $itemPrice = (float) $deal->price;
                        $componentIds = $deal->items->pluck('pos_product_id');
                        $componentNames = PosProduct::where('company_id', $companyId)
                            ->whereIn('id', $componentIds)->pluck('name', 'id');
                        $dealSnapshot = $deal->items->map(fn ($di) => [
                            'product_id' => (int) $di->pos_product_id,
                            'name' => $componentNames[$di->pos_product_id] ?? 'Item',
                            'qty' => (int) $di->quantity,
                        ])->values()->all();
                    } else {
                        $itemId = null;
                    }
                } else {
                    $obj = PosService::where('company_id', $companyId)->where('id', $itemId)->first();
                    if (!$obj) {
                        $itemId = null;
                    }
                    // Same as above — honor cart payload, not DB master.
                }
            }

            // Skip auto-create for cashier "manual" lines — these are ephemeral
            // ad-hoc entries (Quick Type unmatched + "+ Manual" button in
            // inventory-OFF mode) and must NOT pollute the product master.
            // Frontend sends `_manual: true` in the payload to flag them.
            if (!$itemId && $itemType === 'product' && $itemName !== '' && empty($item['_manual'])) {
                $existing = PosProduct::where('company_id', $companyId)
                    ->whereRaw('LOWER(name) = ?', [strtolower($itemName)])
                    ->first();
                if ($existing) {
                    $itemId = $existing->id;
                    $isExempt = (bool) $existing->is_tax_exempt;
                } else {
                    $userExempt = !empty($item['is_tax_exempt']);
                    $newProduct = PosProduct::create([
                        'company_id' => $companyId,
                        'name' => $itemName,
                        'price' => $itemPrice,
                        'tax_rate' => 0,
                        'uom' => 'NOS',
                        'is_active' => true,
                        'is_tax_exempt' => $userExempt,
                        'show_on_sale' => true, // explicit — never trust the DB default (prod drift)
                    ]);
                    $itemId = $newProduct->id;
                    $isExempt = $userExempt;
                }
            }

            $resolved[] = [
                'type' => $itemType,
                'item_id' => $itemId,
                'name' => $itemName,
                'price' => $itemPrice,
                'quantity' => $qty,
                'lineTotal' => round($qty * $itemPrice, 2),
                'isExempt' => $isExempt,
                'notes' => isset($item['special_notes']) ? (string) $item['special_notes'] : null,
                'deal_snapshot' => $dealSnapshot,
            ];
        }
        return $resolved;
    }

    /**
     * Deals (Jul 2026) — expand deal lines into synthetic product-component
     * entries for the inventory engine. Deduct/restore loops only process
     * type==='product', so the deal line itself is skipped and each component
     * moves stock at qty = dealQty × componentQty. ALWAYS snapshot-driven
     * (never live pos_deal_items) so voids/edits restore exactly what was sold,
     * immune to later deal edits/deletes. unit_price 0 on components: the deal
     * price belongs to the deal line; movement sale-values show Rs 0 (accepted).
     */
    private function expandDealComponentsForStock(array $items): array
    {
        $expanded = [];
        foreach ($items as $item) {
            $expanded[] = $item;
            if (($item['type'] ?? 'product') !== 'deal') continue;
            $dealQty = (float) ($item['quantity'] ?? 0);
            $snapshot = $item['deal_snapshot'] ?? null;
            if ($dealQty <= 0 || !is_array($snapshot)) continue;
            foreach ($snapshot as $comp) {
                $pid = (int) ($comp['product_id'] ?? 0);
                $compQty = (float) ($comp['qty'] ?? 0);
                if ($pid <= 0 || $compQty <= 0) continue;
                $expanded[] = [
                    'type' => 'product',
                    'item_id' => $pid,
                    'quantity' => $dealQty * $compQty,
                    'unit_price' => 0,
                ];
            }
        }
        return $expanded;
    }

    private function generateInvoiceNumber(int $companyId): string
    {
        $year = now()->format('Y');

        // Order by the NUMERIC serial, NOT by id: a promoted local bill (old row,
        // low id) is RENUMBERED to the newest serial — id-ordering would then read
        // a stale max from the latest row and hand out a DUPLICATE serial.
        // withoutGlobalScope('hide_archived'): archived rows still occupy the
        // UNIQUE(company_id, invoice_number) index — the serial counter must see
        // them or it re-issues their numbers and every new bill 500s on insert.
        $lastTransaction = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('invoice_number', 'like', "POS-{$year}-%")
            ->orderByRaw(\App\Helpers\DbCompat::cast('SUBSTR(invoice_number, 10)', 'int') . ' DESC')
            ->lockForUpdate()
            ->first();

        if ($lastTransaction && preg_match('/POS-\d{4}-(\d+)/', $lastTransaction->invoice_number, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'POS-' . $year . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    private function generateLocalInvoiceNumber(int $companyId): string
    {
        // Vendor-requested short format: L-001 (per-company, 3-digit pad, grows naturally
        // past 999). Distinct from "POS-{year}-NNNNN" final invoices so cashiers can spot
        // provisional bills at a glance in lists/receipts/PDFs.
        //
        // Owner rule (22 Jul 2026) — SMALLEST FREE NUMBER, not max+1: a new local bill
        // takes the lowest L-number not held by ANY existing row. Two effects the owner
        // asked for: (a) when day-close DELETES local bills, the series restarts from
        // L-001 the next day; (b) when bills are kept, a mid-series deletion frees its
        // number and the next new bill fills that gap, then the series continues upward.
        // Existing bills are NEVER renumbered — only NEW bills take free numbers.
        // Numbers held by day-close ARCHIVED rows are NOT free (they stay in the table
        // + unique index — hence withoutGlobalScope('hide_archived')).
        // Exclude legacy "LOCAL-YYYY-NNNNN" rows — the LIKE 'L-%' pattern would
        // otherwise match both formats and corrupt the counter.
        // lockForUpdate serializes concurrent generators inside the caller's
        // transaction; UNIQUE(company_id, invoice_number) is the final guard.
        // Keep IDENTICAL to RestaurantPosController::generateLocalInvoiceNumber —
        // retail + restaurant share one sequence per company.
        $taken = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('invoice_number', 'like', 'L-%')
            ->where('invoice_number', 'not like', 'LOCAL-%')
            ->lockForUpdate()
            ->pluck('invoice_number');

        $used = [];
        foreach ($taken as $serial) {
            if (preg_match('/^L-(\d+)$/', $serial, $matches)) {
                $used[(int) $matches[1]] = true;
            }
        }

        $next = 1;
        while (isset($used[$next])) {
            $next++;
        }

        return 'L-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    public function billing()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Standalone edition retired (Jul 2026) — everyone sees the PRA POS plans.
        $plans = \App\Models\PricingPlan::where('is_trial', false)->where('product_type', 'pos')->orderBy('price')->get();
        $currentSubscription = \App\Models\Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        return view('pos.billing', compact('company', 'plans', 'currentSubscription'));
    }

    public function businessProfile(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if ($request->isMethod('post')) {
            $rules = [
                'name' => 'required|string|max:255',
                'owner_name' => 'nullable|string|max:255',
                'ntn' => 'nullable|string|max:50',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:30',
                'mobile' => 'nullable|string|max:30',
                'address' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:100',
                'business_activity' => 'nullable|string|max:255',
                'website' => 'nullable|url|max:255',
                'logo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
                'rp_footer_text' => 'nullable|string|max:150',
            ];

            $request->validate($rules);

            // NTN is submitted with EVERY PRA fiscal invoice — do not allow clearing it
            // while PRA reporting is live, or subsequent submissions would carry a null NTN.
            // (NTN is optional at registration; it only becomes mandatory once PRA is ON.)
            if ($company->praReportingActive() && $request->has('ntn') && trim((string) $request->input('ntn')) === '') {
                return back()->withInput()->with('error', __('pos.ntn_cannot_clear_pra_on'));
            }

            $data = $request->only(['name', 'owner_name', 'ntn', 'email', 'phone', 'mobile', 'address', 'city', 'business_activity', 'website']);

            // Receipt display preferences (per-company, POS product scope)
            if ($request->has('receipt_prefs_submitted')) {
                $prefs = $company->invoice_display_prefs ?? [];
                $prefs['pos'] = [
                    'show_address' => $request->has('rp_show_address'),
                    'show_ntn' => $request->has('rp_show_ntn'),
                    'show_email' => $request->has('rp_show_email'),
                    'show_mobile' => $request->has('rp_show_mobile'),
                    'show_footer' => $request->has('rp_show_footer'),
                    'footer_text' => trim((string) $request->input('rp_footer_text', '')) ?: null,
                ];
                $data['invoice_display_prefs'] = $prefs;
            }

            if ($request->hasFile('logo')) {
                if ($company->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($company->logo_path)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_path);
                }
                $path = $request->file('logo')->store('company-logos', 'public');
                $data['logo_path'] = $path;
            }

            if ($request->has('remove_logo') && $request->remove_logo === '1') {
                if ($company->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($company->logo_path)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_path);
                }
                $data['logo_path'] = null;
            }

            $company->update($data);
            return redirect()->route('pos.customize')->with('success', __('pos.business_profile_updated'));
        }

        // F8 — public profile + menu builder data (admin-only section in the view)
        $ppSettings = \App\Http\Controllers\PublicProfileController::settingsFor($company);
        $ppUrl = \App\Http\Controllers\PublicProfileController::publicUrlFor($company);
        $ppQr = $ppUrl ? \App\Support\QrImage::dataUri($ppUrl) : null;
        $ppSelectedIds = \App\Models\PosMenuItem::where('company_id', $company->id)
            ->orderBy('sort_order')->pluck('pos_product_id')->map(fn ($v) => (int) $v)->all();
        $ppProducts = \App\Models\PosProduct::where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('category')->orderBy('name')
            ->get(['id', 'name', 'category', 'price']);

        return view('pos.business-profile', compact('company', 'ppSettings', 'ppUrl', 'ppQr', 'ppSelectedIds', 'ppProducts'));
    }

    public function userProfile(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();

        if ($request->isMethod('post')) {
            $action = $request->input('action');

            if ($action === 'update_profile') {
                $request->validate([
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|max:255|unique:users,email,' . $user->id,
                    'phone' => 'nullable|string|max:30',
                    'username' => 'nullable|string|max:100|unique:users,username,' . $user->id,
                ]);

                $user->update($request->only(['name', 'email', 'phone', 'username']));
                return back()->with('success', __('pos.profile_updated_success'));
            }

            if ($action === 'change_password') {
                $request->validate([
                    'current_password' => 'required',
                    'new_password' => 'required|string|min:8|confirmed',
                ]);

                if (!\Illuminate\Support\Facades\Hash::check($request->current_password, $user->password)) {
                    return back()->withErrors(['current_password' => __('pos.current_password_incorrect')]);
                }

                $passwordUpdate = [
                    'password' => \Illuminate\Support\Facades\Hash::make($request->new_password),
                ];
                // Team roles keep the admin-viewable encrypted copy in sync
                // (owner, Jul 2026) — otherwise a self-service change would
                // show the admin a stale password on /pos/team. The pos_admin
                // (owner) account never stores a viewable copy.
                // PROD schema-drift guard: skip if the migration hasn't landed.
                if (in_array($user->pos_role, ['pos_cashier', 'pos_manager', 'pos_kitchen', 'pos_waiter'], true)
                    && \Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_team_password_enc')) {
                    $passwordUpdate['pos_team_password_enc'] = \Illuminate\Support\Facades\Crypt::encryptString($request->new_password);
                }
                $user->update($passwordUpdate);
                return back()->with('success', __('pos.password_changed_success'));
            }
        }

        return view('pos.user-profile', compact('user'));
    }

    public function dayCloseReport(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Default = the OPEN trading day (business day): after midnight, before
        // 6 AM, with yesterday still un-closed, the page must land on yesterday.
        $date = $request->get('date', \App\Services\PosBusinessDay::current($companyId));

        $existingReport = PosDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)
            ->first();

        // Local (non-PRA) bills are excluded from the day-close view & figures —
        // visible only in the isolated Local Bills Portal (pos_role='local_viewer').
        $transactions = PosTransaction::where('company_id', $companyId)
            ->where('business_date', $date)
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        // Cash/card/other via the ONE shared alias set (PosPaymentBuckets):
        // universal-screen 'card' is stored as 'debit_card', so matching only
        // 'card' would report Rs 0 card sales (and dump them into "Other").
        $payBuckets = PosPaymentBuckets::split($transactions);

        $stats = (object) [
            'total_invoices' => $transactions->count(),
            'pra_invoices' => $transactions->where('pra_status', 'submitted')->count(),
            'local_invoices' => $transactions->whereIn('pra_status', ['local', null])->count(),
            'offline_invoices' => $transactions->where('pra_status', 'offline')->count(),
            'gross_sales' => $transactions->sum('subtotal'),
            'total_discount' => $transactions->sum('discount_amount'),
            'net_sales' => $transactions->sum('subtotal') - $transactions->sum('discount_amount'),
            'total_tax' => $transactions->sum('tax_amount'),
            'total_amount' => $transactions->sum('total_amount'),
            'cash_amount' => $payBuckets['cash'],
            'card_amount' => $payBuckets['card'],
            'other_amount' => $payBuckets['other'],
            'first_invoice' => $transactions->first(),
            'last_invoice' => $transactions->last(),
        ];

        $cashierBreakdown = $transactions->groupBy(fn($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            return (object) [
                'count' => $group->count(),
                'revenue' => $group->sum('total_amount'),
                'tax' => $group->sum('tax_amount'),
            ];
        });

        $previousReports = PosDayCloseReport::where('company_id', $companyId)
            ->orderBy('report_date', 'desc')
            ->limit(10)
            ->get();

        // Comprehensive day-close (owner request Jul 2026): show what the wash WILL
        // touch — this day's local bills PLUS backlog left over from earlier
        // un-closed dates. Mirrors the wash selectors in performDayClose exactly
        // (un-archived + no fiscal number; hide_archived global scope already
        // filters archived rows).
        $pendingBase = fn () => PosTransaction::where('company_id', $companyId)
            ->where('business_date', '<=', $date)
            ->whereNull('pra_invoice_number');
        $pendingProv = $pendingBase()
            ->where('invoice_mode', 'local')
            ->where('pra_status', 'local')
            // Draft guard mirrors the wash and stays on CALENDAR created_at:
            // an after-midnight draft (cashier mid-sale at 00:30) must survive
            // yesterday's 01:30 close — its business_date equals the close date,
            // but its calendar date does not.
            ->where(function ($q) use ($date) {
                $q->whereDate('created_at', $date)
                    ->orWhere('status', '!=', 'draft');
            })
            ->get(['id', 'created_at', 'business_date', 'total_amount']);
        $pendingFinal = $pendingBase()
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->whereNull('pra_status')
            ->get(['id', 'created_at', 'business_date', 'total_amount']);
        $localWash = (object) [
            'prov_count' => $pendingProv->count(),
            'prov_amount' => (float) $pendingProv->sum('total_amount'),
            'prov_backlog' => $pendingProv->filter(fn ($t) => $t->business_date && $t->business_date < $date)->count(),
            'final_count' => $pendingFinal->count(),
            'final_amount' => (float) $pendingFinal->sum('total_amount'),
            'final_backlog' => $pendingFinal->filter(fn ($t) => $t->business_date && $t->business_date < $date)->count(),
        ];

        $analytics = $this->buildDayCloseAnalytics($companyId, $date, $transactions, $company);

        // Delivery Riders (Jul 2026): live rider cash figures for the recon preview
        // (unsettled rider cash is OUT of the drawer; earlier-day settlements are IN).
        $riderFigures = $this->buildRiderDayFigures($companyId, $date);

        // Opening Cash Balance (Jul 2026): day-start entry auto-fills the
        // reconciliation's opening float for this date.
        $dayOpening = \App\Models\PosDayOpening::forDate($companyId, $date);

        // Day-close warning (ZFC 28 Jul 2026, detailed 3 Aug 2026): open held
        // orders / occupied tables must be surfaced BEFORE closing — otherwise
        // they dangle into tomorrow (ZFC: 5 tables sat occupied for 2 days and
        // nobody noticed). Shows table numbers + amounts, restaurant-mode only.
        $openHeld = $this->openHeldOrdersSummary($companyId, $company);
        $openOrders = $openHeld->count;
        $occupiedTables = $openHeld->tables;

        return view('pos.day-close', compact('company', 'date', 'stats', 'existingReport', 'cashierBreakdown', 'previousReports', 'transactions', 'localWash', 'analytics', 'riderFigures', 'dayOpening', 'openOrders', 'occupiedTables', 'openHeld'));
    }

    /**
     * Open held-order summary for day-close warnings (ZFC 3 Aug 2026): how many
     * orders are still un-settled, which table numbers, and how much money is
     * sitting on them. Restaurant-mode companies only (plan-allowed + toggled on);
     * everyone else gets a zeroed summary so the warning block never renders.
     * Purely informational — day-close is NEVER blocked by open orders.
     */
    private function openHeldOrdersSummary(int $companyId, ?Company $company): object
    {
        $empty = (object) ['count' => 0, 'tables' => 0, 'tableNumbers' => '', 'amount' => 0.0, 'noTableCount' => 0];
        $restaurantEnabled = $company
            && \App\Services\PosFeatureService::restaurantAllowed($company)
            && (bool) ($company->restaurant_mode ?? false);
        if (! $restaurantEnabled || ! \Schema::hasTable('restaurant_orders')) {
            return $empty;
        }

        // Same "open" definition as the TABLE board: held/preparing/ready —
        // anything not completed/cancelled. Item-less shells (created then
        // abandoned before adding anything) carry no money and no KOT — skip.
        $orders = \App\Models\RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->whereHas('items')
            ->with('table:id,table_number')
            ->get(['id', 'table_id', 'total_amount']);

        if ($orders->isEmpty()) {
            return $empty;
        }

        $tableNumbers = $orders->filter(fn ($o) => $o->table)
            ->map(fn ($o) => $o->table->table_number)
            ->unique()
            ->sort(SORT_NATURAL)
            ->values();

        return (object) [
            'count' => $orders->count(),
            'tables' => $tableNumbers->count(),
            'tableNumbers' => $tableNumbers->implode(', '),
            'amount' => (float) $orders->sum('total_amount'),
            'noTableCount' => $orders->whereNull('table_id')->count(),
        ];
    }

    public function closeDayReport(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        // Default = the OPEN trading day (business day) — same rule as the page.
        $date = $request->input('date', \App\Services\PosBusinessDay::current($companyId));

        // Local-bill wash at day-close now follows the STANDING company policy set by
        // an admin in Customize POS → Local Billing (save=archive | delete, per bill
        // kind). Cashiers closing the day merely trigger that admin decision — no
        // per-close purge checkbox / cashier authority question anymore.
        // Cash reconciliation (optional): opening float + physically-counted cash.
        $request->validate([
            'opening_float' => 'nullable|numeric|min:0|max:99999999',
            'counted_cash' => 'nullable|numeric|min:0|max:99999999',
        ]);
        $cashRecon = null;
        if ($request->filled('opening_float') || $request->filled('counted_cash')) {
            $cashRecon = [
                'opening_float' => $request->filled('opening_float') ? (float) $request->input('opening_float') : null,
                'counted_cash' => $request->filled('counted_cash') ? (float) $request->input('counted_cash') : null,
            ];
        }
        // Opening Cash Balance (Jul 2026): if the cashier left the opening blank,
        // fall back to the day-start recorded opening (performDayClose also
        // self-heals this, but doing it here keeps the request payload honest).
        if (($cashRecon['opening_float'] ?? null) === null) {
            $recordedOpening = \App\Models\PosDayOpening::forDate($companyId, $date);
            if ($recordedOpening !== null) {
                $cashRecon = $cashRecon ?? [];
                $cashRecon['opening_float'] = (float) $recordedOpening->opening_cash;
                $cashRecon['counted_cash'] = $cashRecon['counted_cash'] ?? null;
            }
        }
        $result = $this->performDayClose($companyId, $date, $user?->id, $request->input('notes'), $cashRecon);

        if ($result['status'] === 'exists') {
            return back()->with('error', __('pos.dayclose_report_exists'));
        }
        if ($result['status'] === 'empty') {
            return back()->with('error', __('pos.dayclose_no_transactions'));
        }

        $msg = __('pos.dayclose_report_generated', ['number' => $result['report_number'], 'date' => \Carbon\Carbon::parse($date)->format('d M Y')]);
        if ($result['archived'] > 0) {
            $msg .= __('pos.dayclose_bills_archived', ['count' => $result['archived']]);
        }
        if (($result['deleted'] ?? 0) > 0) {
            $msg .= __('pos.dayclose_bills_deleted', ['count' => $result['deleted']]);
        }
        $backlogSwept = array_sum(array_column($result['summary'] ?? [], 'backlog'));
        if ($backlogSwept > 0) {
            $msg .= __('pos.dayclose_backlog_included', ['count' => $backlogSwept]);
        }
        // Sweep summary (Task 157, FBR pattern): only when the 'finalize' (Khud
        // Final) policy actually ran and finalized something. Breaks down PRA
        // outcomes so the cashier knows how many were submitted to PRA, how many
        // are queued for the desktop agent (Agent Sync), and how many are saved
        // Offline (retryable from Transactions — never lost). Zero-count branches skip.
        $sweep = $result['finalize_sweep'] ?? null;
        if (($sweep['finalized'] ?? 0) > 0) {
            $msg .= __('pos.dayclose_bills_finalized', ['count' => $sweep['finalized']]);
            if (($sweep['submitted'] ?? 0) > 0) {
                $msg .= __('pos.dayclose_bills_submitted_pra', ['count' => $sweep['submitted']]);
            }
            if (($sweep['queued'] ?? 0) > 0) {
                $msg .= __('pos.dayclose_bills_queued_pra', ['count' => $sweep['queued']]);
            }
            if (($sweep['offline'] ?? 0) > 0) {
                $msg .= __('pos.dayclose_bills_offline_pra', ['count' => $sweep['offline']]);
            }
        }
        // Quota warning (Task 166): if the monthly FINAL-bill quota ran out
        // mid-sweep, the leftover provisionals were silently CARRIED. Tell the
        // cashier how many could NOT be finalized — outside the finalized>0
        // block, because quota can block the very first bill. Zero-count skips.
        if (($sweep['quota_blocked'] ?? 0) > 0) {
            $msg .= __('pos.dayclose_bills_quota_blocked', ['count' => $sweep['quota_blocked']]);
        }
        return back()->with('success', $msg);
    }

    /**
     * Comprehensive day-close analytics (owner request Jul 2026) shared by the
     * day-close page, the A4 PDF and the 80mm thermal Z-report: category-wise
     * sales, top products, hourly breakdown, PRA submission health, discount &
     * deals summary, order-type split (restaurant-gated), averages and
     * yesterday / last-week comparisons. Pure read — computed live from the
     * already-filtered PRA-mode transaction set (local bills stay excluded).
     */
    private function buildDayCloseAnalytics(int $companyId, string $date, $transactions, ?Company $company): object
    {
        $ids = $transactions->pluck('id')->all();

        $items = empty($ids) ? collect() : \App\Models\PosTransactionItem::whereIn('transaction_id', $ids)
            ->get(['transaction_id', 'item_type', 'item_id', 'item_name', 'quantity', 'subtotal', 'tax_amount', 'item_discount_amount', 'deal_snapshot']);

        // Category resolution: product items → pos_products.category (company-scoped
        // lookup — shared-table trap); services & manual items get fixed buckets.
        $productIds = $items->where('item_type', 'product')->pluck('item_id')->filter()->unique()->values();
        $categoryMap = $productIds->isEmpty() ? collect() : \App\Models\PosProduct::where('company_id', $companyId)
            ->whereIn('id', $productIds)->pluck('category', 'id');

        $items->each(function ($it) use ($categoryMap) {
            if ($it->item_type === 'product') {
                $cat = trim((string) ($categoryMap[$it->item_id] ?? ''));
                $it->resolved_category = $cat !== '' ? $cat : 'Uncategorized';
            } elseif ($it->item_type === 'service') {
                $it->resolved_category = 'Services';
            } else {
                $it->resolved_category = 'Manual / Other';
            }
        });

        $categoryRevenueTotal = (float) $items->sum('subtotal');
        $categories = $items->groupBy('resolved_category')->map(function ($g) use ($categoryRevenueTotal) {
            $revenue = (float) $g->sum('subtotal');
            return (object) [
                'qty' => (float) $g->sum('quantity'),
                'revenue' => $revenue,
                'tax' => (float) $g->sum('tax_amount'),
                'share' => $categoryRevenueTotal > 0 ? round($revenue / $categoryRevenueTotal * 100, 1) : 0,
            ];
        })->sortByDesc('revenue');

        $topProducts = $items->groupBy('item_name')->map(function ($g) {
            return (object) [
                'qty' => (float) $g->sum('quantity'),
                'revenue' => (float) $g->sum('subtotal'),
            ];
        })->sortByDesc('revenue')->take(10);

        // Hourly sales — full 24-slot map so the chart x-axis stays stable.
        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = (object) ['count' => 0, 'revenue' => 0.0];
        }
        foreach ($transactions as $t) {
            if (!$t->created_at) {
                continue;
            }
            $h = (int) $t->created_at->format('G');
            $hourly[$h]->count++;
            $hourly[$h]->revenue += (float) $t->total_amount;
        }

        // PRA submission health — every pipeline state at a glance.
        $praHealth = (object) [
            'submitted' => $transactions->where('pra_status', 'submitted')->count(),
            'pending' => $transactions->where('pra_status', 'pending')->count(),
            'offline' => $transactions->where('pra_status', 'offline')->count(),
            'failed' => $transactions->where('pra_status', 'failed')->count(),
            'not_reported' => $transactions->whereNull('pra_status')->count(),
        ];

        $discountBills = $transactions->filter(fn ($t) => (float) $t->discount_amount > 0);
        $itemDiscountTotal = (float) $items->sum('item_discount_amount');
        $discounts = (object) [
            'bill_count' => $discountBills->count(),
            'bill_total' => (float) $discountBills->sum('discount_amount'),
            'item_total' => $itemDiscountTotal,
            'total' => (float) $discountBills->sum('discount_amount') + $itemDiscountTotal,
        ];

        // Restaurant-only extras: deals performance + order-type split.
        $restaurantEnabled = $company
            && \App\Services\PosFeatureService::restaurantAllowed($company)
            && (bool) ($company->restaurant_mode ?? false);
        $deals = collect();
        $orderTypes = collect();
        if ($restaurantEnabled) {
            $deals = $items->filter(fn ($it) => !empty($it->deal_snapshot))
                ->groupBy('item_name')->map(function ($g) {
                    return (object) [
                        'qty' => (float) $g->sum('quantity'),
                        'revenue' => (float) $g->sum('subtotal'),
                    ];
                })->sortByDesc('revenue')->take(5);

            $orderTypeMap = empty($ids) ? collect() : \App\Models\RestaurantOrder::where('company_id', $companyId)
                ->whereIn('pos_transaction_id', $ids)
                ->pluck('order_type', 'pos_transaction_id');
            $orderTypes = $transactions->groupBy(fn ($t) => $orderTypeMap[$t->id] ?? 'counter')
                ->map(function ($g) {
                    return (object) [
                        'count' => $g->count(),
                        'revenue' => (float) $g->sum('total_amount'),
                    ];
                })->sortByDesc('revenue');
        }

        $billCount = $transactions->count();
        $avgBill = $billCount > 0 ? (float) $transactions->sum('total_amount') / $billCount : 0.0;
        $uniqueCustomers = $transactions
            ->filter(fn ($t) => $t->customer_id || !empty($t->customer_phone) || !empty($t->customer_name))
            ->unique(fn ($t) => $t->customer_id ?: ($t->customer_phone ?: mb_strtolower(trim((string) $t->customer_name))))
            ->count();

        // Yesterday + same-day-last-week comparison (same PRA-mode filter).
        // withoutGlobalScope('hide_archived'): the day-close wash ARCHIVES
        // reporting-OFF finals (invoice_mode 'pra' + NULL pra_status), so an
        // already-closed comparison day would undercount without it.
        $compareFor = function (string $cmpDate) use ($companyId) {
            $row = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('business_date', $cmpDate)
                ->where(function ($q) {
                    $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
                })
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as revenue, COALESCE(SUM(tax_amount),0) as tax')
                ->first();
            return (object) [
                'date' => $cmpDate,
                'invoices' => (int) ($row->cnt ?? 0),
                'revenue' => (float) ($row->revenue ?? 0),
                'tax' => (float) ($row->tax ?? 0),
            ];
        };
        $todayRevenue = (float) $transactions->sum('total_amount');
        $pct = function (float $prev, float $cur): ?float {
            if ($prev <= 0) {
                return null; // no baseline — view shows "—"
            }
            return round(($cur - $prev) / $prev * 100, 1);
        };
        $yesterday = $compareFor(\Carbon\Carbon::parse($date)->subDay()->toDateString());
        $lastWeek = $compareFor(\Carbon\Carbon::parse($date)->subDays(7)->toDateString());
        $comparison = (object) [
            'yesterday' => $yesterday,
            'last_week' => $lastWeek,
            'vs_yesterday_revenue_pct' => $pct($yesterday->revenue, $todayRevenue),
            'vs_yesterday_invoices_pct' => $pct((float) $yesterday->invoices, (float) $billCount),
            'vs_last_week_revenue_pct' => $pct($lastWeek->revenue, $todayRevenue),
            'vs_last_week_invoices_pct' => $pct((float) $lastWeek->invoices, (float) $billCount),
        ];

        return (object) [
            'categories' => $categories,
            'top_products' => $topProducts,
            'hourly' => $hourly,
            'pra_health' => $praHealth,
            'discounts' => $discounts,
            'restaurant_enabled' => $restaurantEnabled,
            'deals' => $deals,
            'order_types' => $orderTypes,
            'avg_bill' => $avgBill,
            'unique_customers' => $uniqueCustomers,
            'comparison' => $comparison,
        ];
    }

    /**
     * Range analytics for the POS Reports page (owner request Jul 2026):
     * date-window deep dive — category breakdown w/ product drill-down, profit
     * (ADMIN-ONLY, pos_products.cost_price based, coverage-aware), previous-
     * period comparison, daily + hourly chart data, cashier performance, top
     * customers, payment split. Respects the tab (pra|local) + cashier filter
     * exactly like the rest of the reports page (applyReportFilters).
     */
    private function buildReportRangeAnalytics(int $companyId, \Carbon\Carbon $from, \Carbon\Carbon $to, string $tab, $cashierFilter, ?Company $company, $user): object
    {
        $isAdminView = (bool) ($user?->isPosAdmin());

        $transactions = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->tap(fn ($q) => $this->applyReportFilters($q, $tab, $cashierFilter))
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'created_at', 'business_date', 'created_by', 'customer_id', 'customer_name', 'customer_phone', 'subtotal', 'total_amount', 'tax_amount', 'discount_amount', 'payment_method']);

        $ids = $transactions->pluck('id')->all();
        $items = empty($ids) ? collect() : \App\Models\PosTransactionItem::whereIn('transaction_id', $ids)
            ->get(['transaction_id', 'item_type', 'item_id', 'item_name', 'quantity', 'subtotal', 'tax_amount', 'item_discount_amount']);

        // Category + cost resolution (company-scoped lookup — shared-table trap).
        $productIds = $items->where('item_type', 'product')->pluck('item_id')->filter()->unique()->values();
        $productMap = $productIds->isEmpty() ? collect() : \App\Models\PosProduct::where('company_id', $companyId)
            ->whereIn('id', $productIds)->get(['id', 'category', 'cost_price'])->keyBy('id');

        $items->each(function ($it) use ($productMap, $isAdminView) {
            $cost = null;
            if ($it->item_type === 'product') {
                $p = $productMap[$it->item_id] ?? null;
                $cat = trim((string) ($p->category ?? ''));
                $it->resolved_category = $cat !== '' ? $cat : 'Uncategorized';
                if ($isAdminView && $p && $p->cost_price !== null && (float) $p->cost_price > 0) {
                    $cost = (float) $p->cost_price * (float) $it->quantity;
                }
            } elseif ($it->item_type === 'service') {
                $it->resolved_category = 'Services';
            } else {
                $it->resolved_category = 'Manual / Other';
            }
            $it->resolved_cost = $cost;
        });

        $revenueTotal = (float) $items->sum('subtotal');
        $categories = $items->groupBy('resolved_category')->map(function ($g) use ($revenueTotal, $isAdminView) {
            $revenue = (float) $g->sum('subtotal');
            $withCost = $g->filter(fn ($it) => $it->resolved_cost !== null);
            $cost = (float) $withCost->sum('resolved_cost');
            $costedRevenue = (float) $withCost->sum('subtotal');
            return (object) [
                'qty' => (float) $g->sum('quantity'),
                'revenue' => $revenue,
                'tax' => (float) $g->sum('tax_amount'),
                'share' => $revenueTotal > 0 ? round($revenue / $revenueTotal * 100, 1) : 0,
                'profit' => ($isAdminView && $withCost->isNotEmpty()) ? round($costedRevenue - $cost, 2) : null,
                'products' => $g->groupBy('item_name')->map(function ($pg) {
                    return (object) [
                        'qty' => (float) $pg->sum('quantity'),
                        'revenue' => (float) $pg->sum('subtotal'),
                    ];
                })->sortByDesc('revenue')->take(15),
            ];
        })->sortByDesc('revenue');

        // Profit summary (ADMIN-ONLY): only items whose product has a cost_price
        // set count toward cost — coverage_pct tells the admin how complete it is.
        $profit = null;
        if ($isAdminView) {
            $withCost = $items->filter(fn ($it) => $it->resolved_cost !== null);
            $cost = (float) $withCost->sum('resolved_cost');
            $costedRevenue = (float) $withCost->sum('subtotal');
            $productQty = (float) $items->where('item_type', 'product')->sum('quantity');
            $costedQty = (float) $withCost->sum('quantity');
            $profit = (object) [
                'cost' => $cost,
                'revenue' => $costedRevenue,
                'profit' => round($costedRevenue - $cost, 2),
                'margin_pct' => $costedRevenue > 0 ? round(($costedRevenue - $cost) / $costedRevenue * 100, 1) : null,
                'coverage_pct' => $productQty > 0 ? (int) round($costedQty / $productQty * 100) : 0,
            ];
        }

        // Daily trend — zero-filled so the chart x-axis has every day of the range.
        $daily = [];
        $cursor = $from->copy()->startOfDay();
        $endDay = $to->copy()->startOfDay();
        while ($cursor->lte($endDay)) {
            $daily[$cursor->toDateString()] = (object) ['count' => 0, 'revenue' => 0.0];
            $cursor->addDay();
        }
        foreach ($transactions as $t) {
            $d = $t->business_date ?: $t->created_at?->toDateString();
            if ($d !== null && isset($daily[$d])) {
                $daily[$d]->count++;
                $daily[$d]->revenue += (float) $t->total_amount;
            }
        }

        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = (object) ['count' => 0, 'revenue' => 0.0];
        }
        foreach ($transactions as $t) {
            if (!$t->created_at) {
                continue;
            }
            $h = (int) $t->created_at->format('G');
            $hourly[$h]->count++;
            $hourly[$h]->revenue += (float) $t->total_amount;
        }

        $cashierNames = User::where('company_id', $companyId)->pluck('name', 'id');
        $cashiers = $transactions->groupBy('created_by')->map(function ($g) use ($cashierNames) {
            $revenue = (float) $g->sum('total_amount');
            return (object) [
                'name' => $cashierNames[$g->first()->created_by] ?? 'Unknown',
                'count' => $g->count(),
                'revenue' => $revenue,
                'tax' => (float) $g->sum('tax_amount'),
                'avg' => round($revenue / max(1, $g->count()), 2),
            ];
        })->sortByDesc('revenue')->values();

        // Sales by Waiter (Table-se-Bill, Jul 2026): waiter attribution rides on
        // restaurant_orders (created_by = waiter, pos_transaction_id linked at
        // settle) — pos_transactions has no waiter column. whereIn on the
        // already-filtered $ids inherits the tab + cashier + date filters.
        $waiters = collect();
        if (!empty($ids)) {
            $txnById = $transactions->keyBy('id');
            $waiters = \App\Models\RestaurantOrder::where('company_id', $companyId)
                ->where('source', 'waiter')
                ->where('status', 'completed')
                ->whereIn('pos_transaction_id', $ids)
                ->get(['created_by', 'pos_transaction_id'])
                ->groupBy('created_by')
                ->map(function ($g) use ($cashierNames, $txnById) {
                    $txns = $g->map(fn ($o) => $txnById[$o->pos_transaction_id] ?? null)->filter();
                    $revenue = (float) $txns->sum('total_amount');
                    return (object) [
                        'name' => $cashierNames[$g->first()->created_by] ?? 'Unknown',
                        'count' => $txns->count(),
                        'revenue' => $revenue,
                        'tax' => (float) $txns->sum('tax_amount'),
                        'avg' => round($revenue / max(1, $txns->count()), 2),
                    ];
                })
                ->filter(fn ($w) => $w->count > 0)
                ->sortByDesc('revenue')->values();
        }

        $topCustomers = $transactions
            ->filter(fn ($t) => $t->customer_id || !empty($t->customer_phone) || trim((string) $t->customer_name) !== '')
            ->groupBy(fn ($t) => $t->customer_id ?: ($t->customer_phone ?: mb_strtolower(trim((string) $t->customer_name))))
            ->map(function ($g) {
                $t0 = $g->first();
                return (object) [
                    'name' => trim((string) $t0->customer_name) !== '' ? $t0->customer_name : ($t0->customer_phone ?: ('Customer #' . $t0->customer_id)),
                    'count' => $g->count(),
                    'revenue' => (float) $g->sum('total_amount'),
                ];
            })->sortByDesc('revenue')->take(10)->values();

        $payments = $transactions->groupBy('payment_method')->map(function ($g) {
            return (object) [
                'count' => $g->count(),
                'revenue' => (float) $g->sum('total_amount'),
            ];
        })->sortByDesc('revenue');

        // Previous equal-length period (immediately before the range, same filters).
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $prevFrom = $from->copy()->subDays($days)->startOfDay();
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevRow = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->tap(fn ($q) => $this->applyReportFilters($q, $tab, $cashierFilter))
            ->whereBetween('business_date', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as revenue, COALESCE(SUM(tax_amount),0) as tax')
            ->first();
        $pct = function (float $prev, float $cur): ?float {
            return $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;
        };

        $revenue = (float) $transactions->sum('total_amount');
        $tax = (float) $transactions->sum('tax_amount');
        $billCount = $transactions->count();
        $summary = (object) [
            'bills' => $billCount,
            'revenue' => $revenue,
            'tax' => $tax,
            'discount' => (float) $transactions->sum('discount_amount') + (float) $items->sum('item_discount_amount'),
            'avg_bill' => $billCount > 0 ? $revenue / $billCount : 0.0,
            'unique_customers' => $transactions
                ->filter(fn ($t) => $t->customer_id || !empty($t->customer_phone) || trim((string) $t->customer_name) !== '')
                ->unique(fn ($t) => $t->customer_id ?: ($t->customer_phone ?: mb_strtolower(trim((string) $t->customer_name))))
                ->count(),
        ];
        $previous = (object) [
            'from' => $prevFrom->toDateString(),
            'to' => $prevTo->toDateString(),
            'bills' => (int) ($prevRow->cnt ?? 0),
            'revenue' => (float) ($prevRow->revenue ?? 0),
            'tax' => (float) ($prevRow->tax ?? 0),
            'revenue_pct' => $pct((float) ($prevRow->revenue ?? 0), $revenue),
            'bills_pct' => $pct((float) ($prevRow->cnt ?? 0), (float) $billCount),
            'tax_pct' => $pct((float) ($prevRow->tax ?? 0), $tax),
        ];

        return (object) [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => $summary,
            'previous' => $previous,
            'categories' => $categories,
            'profit' => $profit,
            'is_admin_view' => $isAdminView,
            'daily' => $daily,
            'hourly' => $hourly,
            'cashiers' => $cashiers,
            'waiters' => $waiters,
            'top_customers' => $topCustomers,
            'payments' => $payments,
        ];
    }

    /**
     * Shared range parsing for the reports analytics surfaces: defaults to the
     * current month, swaps reversed inputs, caps the window at 366 days.
     *
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    private function resolveReportRange(Request $request): array
    {
        try {
            $from = $request->filled('from') ? \Carbon\Carbon::parse($request->input('from'))->startOfDay() : now()->startOfMonth();
        } catch (\Throwable) {
            $from = now()->startOfMonth();
        }
        try {
            $to = $request->filled('to') ? \Carbon\Carbon::parse($request->input('to'))->endOfDay() : now()->endOfDay();
        } catch (\Throwable) {
            $to = now()->endOfDay();
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        return [$from, $to];
    }

    /**
     * Core day-close logic shared by the HTTP endpoint (closeDayReport) and the
     * 6 AM next-morning auto-close command (pos:auto-dayclose).
     *
     * LOCAL-BILL WASH (owner rule Jul 2026 — REVERSES the old "never sweep
     * reporting-OFF finals" invariant): every day-close washes that day's local
     * bills per the STANDING company policy from Customize POS → Local Billing:
     *   - deliberate provisionals (invoice_mode='local' + pra_status='local')
     *     → pos_dayclose_provisional_action: 'save' (archive) | 'delete'
     *   - reporting-OFF finals (completed + invoice_mode pra/NULL + pra_status NULL
     *     + no fiscal number) → pos_dayclose_final_local_action: 'save' | 'delete'
     * On 'delete' with pos_customer_spend_persist ON, a pos_customer_spend_snapshots
     * ledger row is written FIRST so customer purchase history survives.
     * GUARD: bills with a non-NULL pra_status (pending/submitted/completed/failed/
     * offline) or a PRA fiscal number are NEVER touched — fiscal pipeline is sacred.
     *
     * This method does NOT enforce role authority, so authorize before calling.
     *
     * @return array{status:string,report:?\App\Models\PosDayCloseReport,archived:int,deleted:int,report_number:?string}
     *         status is one of 'created' | 'exists' | 'empty'.
     *
     * Delivery Riders (Jul 2026) — helper below: per-day rider figures for the Z-report.
     *
     * cash_out = TODAY's PRA-set cash delivery bills still unsettled at close —
     *            that cash sits with the rider, NOT in the drawer.
     * cash_in  = settlements received TODAY for EARLIER days' PRA-set cash bills —
     *            cash that entered the drawer today but is not in today's cash sales.
     * Both stay PRA-set (invoice_mode 'pra'/NULL) for consistency with the stored
     * cash_amount; the per-rider rows cover ALL rider bills of the day (operational
     * truth for the shop). Schema-guarded — returns inactive on prod mid-deploy.
     */
    private function buildRiderDayFigures(int $companyId, string $date): array
    {
        $empty = ['active' => false, 'riders' => [], 'cash_out' => 0.0, 'cash_in' => 0.0];
        try {
            if (!\Schema::hasTable('pos_riders') || !\Schema::hasColumn('pos_transactions', 'rider_id')) {
                return $empty;
            }

            $dayBills = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('business_date', $date)
                ->whereNotNull('rider_id')
                ->get();

            // rider_settled_at stays on the REAL calendar date (settlement
            // timestamps carry no business date) — known v1 limitation: a 1 AM
            // settlement counts toward the calendar day, not the open trading day.
            $cashIn = (float) PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->whereNotNull('rider_id')
                ->where('payment_method', PosPaymentBuckets::CASH)
                ->whereNotNull('rider_settlement_id')
                ->whereDate('rider_settled_at', $date)
                ->where('business_date', '<', $date)
                ->where(function ($q) {
                    $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
                })
                ->sum('total_amount');

            if ($dayBills->isEmpty() && $cashIn == 0.0) {
                return $empty;
            }

            $isOpenCash = fn ($t) => $t->payment_method === PosPaymentBuckets::CASH
                && !$t->rider_settlement_id
                && $t->delivery_status !== 'returned';

            $cashOut = (float) $dayBills
                ->filter(fn ($t) => ($t->invoice_mode === 'pra' || $t->invoice_mode === null) && $isOpenCash($t))
                ->sum('total_amount');

            $riderNames = \App\Models\PosRider::where('company_id', $companyId)
                ->whereIn('id', $dayBills->pluck('rider_id')->unique())
                ->pluck('name', 'id');
            $riders = [];
            foreach ($dayBills->groupBy('rider_id') as $rid => $rows) {
                $riders[] = [
                    'name' => $riderNames[$rid] ?? ('Rider #' . $rid),
                    'deliveries' => $rows->count(),
                    'delivered' => $rows->where('delivery_status', 'delivered')->count(),
                    'returned' => $rows->where('delivery_status', 'returned')->count(),
                    'cash_total' => round((float) $rows->filter(fn ($t) => $t->payment_method === PosPaymentBuckets::CASH && $t->delivery_status !== 'returned')->sum('total_amount'), 2),
                    'cash_pending' => round((float) $rows->filter($isOpenCash)->sum('total_amount'), 2),
                ];
            }

            return ['active' => true, 'riders' => $riders, 'cash_out' => round($cashOut, 2), 'cash_in' => round($cashIn, 2)];
        } catch (\Throwable $e) {
            // Rider figures are reporting sugar — never let them break day-close.
            return $empty;
        }
    }

    /**
     * AUTO-FINALIZE SWEEP ('finalize' provisional policy, owner option Aug 2026).
     * Promotes every pending provisional (completed/local/local triple, no fiscal
     * number, un-archived, business_date <= close date) through promoteProvisionalCore —
     * the EXACT path F10 Make Final uses: quota gate per bill, current-month gate,
     * re-tax for the STORED payment method, whole-rupee rounding / tax-inclusive
     * snapshot math, serial split. Reporting decision is company-level
     * (praReportingActive) — the 6 AM auto close runs user-less.
     * PRA submit happens here, OUTSIDE performDayClose's report transaction:
     *   - Agent-Sync companies: bill stays 'pending', agent picks it up (queued).
     *   - Cloud: sendInvoice; connection failure → pra_status='offline' (retryable).
     * NO receipt print — customer is not present (promoteNoPrint semantics; printing
     * is client-side anyway). Bills that cannot be finalized (quota exhausted, older
     * month, drafts, races) are left untouched — the wash carries them forward.
     *
     * @return array{finalized:int,finalized_amount:float,submitted:int,queued:int,offline:int,quota_blocked:int,skipped:int}
     */
    private function finalizeProvisionalsAtDayClose(int $companyId, Company $company, string $date): array
    {
        $sweep = ['finalized' => 0, 'finalized_amount' => 0.0, 'submitted' => 0, 'queued' => 0, 'offline' => 0, 'quota_blocked' => 0, 'skipped' => 0];

        $reportingOn = $company->praReportingActive();
        $agentMode = $company->agentHandlesPra();
        $praService = null;

        // Same selector as the provisional wash set, restricted to COMPLETED bills —
        // a draft is a live/abandoned cart, never something to send to the tax record.
        $rows = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('business_date', '<=', $date)
            ->whereNull('pra_invoice_number')
            ->where('is_archived', false)
            ->where('invoice_mode', 'local')
            ->where('pra_status', 'local')
            ->where('status', 'completed')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            // MONTH GATE mirror (owner rule Jul 2026): previous-month locals are
            // closed — never submitted late. Sweep skips them; wash carries them.
            if ($row->created_at && $row->created_at->lt(now()->startOfMonth())) {
                $sweep['skipped']++;
                continue;
            }
            // Quota gate PER BILL — each finalize consumes monthly quota exactly
            // like an F10 promote. Once exhausted, the rest stay provisional.
            $quota = \App\Services\PlanLimitService::canCreatePosBill($companyId);
            if (!($quota['allowed'] ?? true)) {
                $sweep['quota_blocked'] = $rows->count() - $sweep['finalized'] - $sweep['skipped'];
                break;
            }
            try {
                // null method = keep the STORED payment method (no cashier present).
                $res = $this->promoteProvisionalCore($companyId, $company, (int) $row->id, null, $reportingOn);
            } catch (\Throwable $e) {
                // Race / no-longer-provisional / month-closed — carry it, never fail the close.
                $sweep['skipped']++;
                continue;
            }
            $sweep['finalized']++;
            $sweep['finalized_amount'] += (float) ($res['total'] ?? 0);

            if (!$reportingOn) {
                continue; // reporting-OFF: regulator-mode final ('pra' + NULL status), nothing to send
            }
            if ($agentMode) {
                $sweep['queued']++; // stays 'pending' — desktop agent polls within 10s
                continue;
            }
            $tx = PosTransaction::where('company_id', $companyId)->find($row->id);
            if (!$tx) {
                continue;
            }
            try {
                $praService = $praService ?: new PraIntegrationService($company);
                $result = $praService->sendInvoice($tx);
                if (!empty($result['success'])) {
                    $sweep['submitted']++;
                } else {
                    // PRA rejected — service already stamped 'failed'; retryable from Transactions.
                    $sweep['offline']++;
                }
            } catch (\Throwable $e) {
                // Internet/PRA down at 6 AM — standard offline fallback, retryable.
                $tx->update(['pra_status' => 'offline']);
                $sweep['offline']++;
            }
        }

        $sweep['finalized_amount'] = round($sweep['finalized_amount'], 2);
        return $sweep;
    }

    public function performDayClose(int $companyId, string $date, ?int $closedBy, ?string $notes = null, ?array $cashRecon = null): array
    {
        $existing = PosDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)
            ->first();

        if ($existing) {
            return ['status' => 'exists', 'report' => $existing, 'archived' => 0, 'deleted' => 0, 'report_number' => $existing->report_number];
        }

        // Policy resolved UP FRONT (Aug 2026): the 'finalize' sweep must run BEFORE
        // the day's PRA-set figures are queried, so freshly-finalized bills count in
        // this very Z-report (and leave the provisional wash selector).
        $company = Company::find($companyId);
        $provAction = in_array($company->pos_dayclose_provisional_action ?? 'save', ['save', 'delete', 'carry', 'finalize'], true)
            ? ($company->pos_dayclose_provisional_action ?? 'save') : 'save';
        $finalAction = in_array($company->pos_dayclose_final_local_action ?? 'save', ['save', 'delete'], true)
            ? ($company->pos_dayclose_final_local_action ?? 'save') : 'save';
        $spendPersist = (bool) ($company->pos_customer_spend_persist ?? true);

        // ── AUTO-FINALIZE SWEEP (owner option, Aug 2026): promote every pending
        // provisional through the SAME core path F10 Make Final uses (quota gate,
        // month gate, re-tax + whole-rupee rounding, PRA submit with offline
        // fallback). NO receipt print (customer not present). Leftovers that could
        // not be finalized (quota out, older month, drafts, PRA-failed) are
        // CARRIED — never archived/deleted, they stay finalizable tomorrow.
        $finalizeSweep = null;
        if ($provAction === 'finalize') {
            $finalizeSweep = $this->finalizeProvisionalsAtDayClose($companyId, $company, $date);
        }

        // Local (non-PRA) bills stay OUT of the stored day-close figures — they are
        // visible only in the isolated Local Bills Portal. The purge/archive query
        // below still targets them so day-close archiving keeps working.
        $transactions = PosTransaction::where('company_id', $companyId)
            ->where('business_date', $date)
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        // Wash candidates up to AND INCLUDING the close date (owner rule Jul 2026:
        // leftover local bills from earlier un-closed dates must also get washed —
        // "purani dates ke local bills bhi close hon"). Matches the wash selectors
        // below: both kinds, un-archived, no fiscal number.
        $hasLocalBills = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('business_date', '<=', $date)
            ->whereNull('pra_invoice_number')
            ->where('is_archived', false)
            ->where(function ($q) use ($date) {
                $q->where(function ($qq) use ($date) {
                    $qq->where('invoice_mode', 'local')->where('pra_status', 'local')
                        // Draft guard mirrors the wash (CALENDAR created_at —
                        // see wash note): backlog counts only non-draft
                        // provisionals; close-date drafts still wash.
                        ->where(function ($d) use ($date) {
                            $d->whereDate('created_at', $date)
                                ->orWhere('status', '!=', 'draft');
                        });
                })->orWhere(function ($qq) {
                    $qq->where('status', 'completed')
                        ->where(function ($m) {
                            $m->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
                        })
                        ->whereNull('pra_status');
                });
            })
            ->exists();

        if ($transactions->isEmpty() && !$hasLocalBills) {
            return ['status' => 'empty', 'report' => null, 'archived' => 0, 'deleted' => 0, 'report_number' => null];
        }

        $reportCount = PosDayCloseReport::where('company_id', $companyId)->count();
        $reportNumber = 'ZRPT-POS-' . str_pad($reportCount + 1, 5, '0', STR_PAD_LEFT);

        // Cash/card/other via the ONE shared alias set (PosPaymentBuckets) —
        // 'card' is stored as 'debit_card'; ='card' matching reported Rs 0 card
        // sales on the Z-report (live incident, Jul 2026).
        $payBuckets = PosPaymentBuckets::split($transactions);

        $data = [
            'company_id' => $companyId,
            'report_date' => $date,
            'report_number' => $reportNumber,
            'total_invoices' => $transactions->count(),
            'pra_invoices' => $transactions->where('pra_status', 'submitted')->count(),
            'local_invoices' => $transactions->whereIn('pra_status', ['local', null])->count(),
            'offline_invoices' => $transactions->where('pra_status', 'offline')->count(),
            'gross_sales' => $transactions->sum('subtotal'),
            'total_discount' => $transactions->sum('discount_amount'),
            'net_sales' => $transactions->sum('subtotal') - $transactions->sum('discount_amount'),
            'total_tax' => $transactions->sum('tax_amount'),
            'total_amount' => $transactions->sum('total_amount'),
            'cash_amount' => $payBuckets['cash'],
            'card_amount' => $payBuckets['card'],
            'other_amount' => $payBuckets['other'],
            'first_invoice_number' => $transactions->first()->invoice_number ?? null,
            'last_invoice_number' => $transactions->last()->invoice_number ?? null,
            'first_invoice_time' => $transactions->first()->created_at ?? null,
            'last_invoice_time' => $transactions->last()->created_at ?? null,
            'closed_by' => $closedBy,
            'notes' => $notes,
        ];

        // Delivery Riders (Jul 2026): rider cash figures for this day — computed
        // BEFORE the wash so archived/deleted local bills still count.
        $riderFigures = $this->buildRiderDayFigures($companyId, $date);

        // Cash reconciliation (Z-report): expected = opening float + cash sales
        // − rider cash still out with riders (unsettled today's cash deliveries)
        // + rider cash received today for earlier days' bills;
        // variance = counted − expected. Columns are nullable + schema-guarded
        // (prod drift self-heal).
        // Opening Cash Balance (Jul 2026): the day-start recorded opening is the
        // fallback when the close request didn't carry one — this also covers the
        // MIDNIGHT AUTO close ($cashRecon null), so the Z-report still shows the
        // opening + expected cash even without an evening count.
        if (\Schema::hasColumn('pos_day_close_reports', 'opening_float')) {
            $openingFloat = $cashRecon['opening_float'] ?? null;
            $countedCash = $cashRecon['counted_cash'] ?? null;
            if ($openingFloat === null) {
                $recordedOpening = \App\Models\PosDayOpening::forDate($companyId, $date);
                if ($recordedOpening !== null) {
                    $openingFloat = (float) $recordedOpening->opening_cash;
                }
            }
            if ($openingFloat !== null || $countedCash !== null) {
                $expectedCash = round((float) ($openingFloat ?? 0) + (float) $data['cash_amount']
                    - (float) $riderFigures['cash_out'] + (float) $riderFigures['cash_in'], 2);
                $data['opening_float'] = $openingFloat;
                $data['counted_cash'] = $countedCash;
                $data['expected_cash'] = $expectedCash;
                $data['cash_variance'] = $countedCash !== null ? round((float) $countedCash - $expectedCash, 2) : null;
            }
        }

        $hashString = json_encode($data);
        $data['hash'] = hash('sha256', $hashString);

        // ── LOCAL-BILL WASH (per-company policy, Customize POS → Local Billing) ──
        // Runs on EVERY day-close. Two disjoint bill sets, each with its own action:
        //   provisionals      = invoice_mode='local' + pra_status='local' (deliberate)
        //   reporting-OFF finals = completed + invoice_mode pra/NULL + pra_status NULL
        // Both selectors also require pra_invoice_number NULL — a fiscal number OR any
        // non-NULL pra_status (pending/submitted/completed/failed/offline) means the
        // bill is in the PRA pipeline and is NEVER touched.
        // 'save'  → archive (is_archived=true; recoverable, still in Local reports tab)
        // 'delete'→ permanent delete; with spend-persist ON a pos_customer_spend_snapshots
        //           ledger row is written FIRST for bills linked to a customer.
        // Wrapped in one DB transaction so report + wash succeed/fail atomically.
        // 'carry' (Aug 2026, customer q: "6 baje auto-close par Make Final bhool
        // gaye to?"): pending provisionals are LEFT UNTOUCHED — they stay in F10
        // and can be made final the next day. Day close itself still happens.
        // 'finalize' (Aug 2026): sweep already ran above; leftovers behave like carry.
        // ($company / $provAction / $finalAction / $spendPersist resolved up top.)

        // Rider wash DELETE-guard (Jul 2026): a cash delivery bill whose rider has
        // NOT settled yet is a live khata entry — permanent delete would erase the
        // proof of what the rider owes. Those bills get ARCHIVED instead (recoverable,
        // khata queries use withoutGlobalScope so they still count). Schema-guarded.
        $riderGuardReady = \Schema::hasColumn('pos_transactions', 'rider_id');

        $archivedCount = 0;
        $deletedCount = 0;
        $report = null;
        $localSummary = [];
        \DB::transaction(function () use ($data, $companyId, $date, $provAction, $finalAction, $spendPersist, $riderGuardReady, $riderFigures, $finalizeSweep, &$archivedCount, &$deletedCount, &$report, &$localSummary) {
            $report = PosDayCloseReport::create($data);

            // BACKLOG SWEEP (owner rule Jul 2026): wash covers bills up to AND
            // INCLUDING the close date, so local bills left over from earlier
            // un-closed dates finally get washed instead of lingering forever.
            $baseQuery = function () use ($companyId, $date) {
                return PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->where('business_date', '<=', $date)
                    ->whereNull('pra_invoice_number')
                    ->where('is_archived', false);
            };

            $sets = [
                'provisional' => [
                    'action' => $provAction,
                    'query' => $baseQuery()
                        ->where('invoice_mode', 'local')
                        ->where('pra_status', 'local')
                        // DRAFT GUARD (stays on CALENDAR created_at, NOT
                        // business_date): an after-midnight draft (cashier
                        // mid-sale at 00:30) carries yesterday's business_date —
                        // switching this equality would wash the live cart during
                        // yesterday's 01:30 close. The close DATE keeps its
                        // pre-existing full wash (incl. that day's abandoned
                        // draft carts); the BACKLOG sweep takes only non-draft
                        // provisionals — a saved draft cart from an earlier day
                        // stays resumable.
                        ->where(function ($q) use ($date) {
                            $q->whereDate('created_at', $date)
                                ->orWhere('status', '!=', 'draft');
                        }),
                ],
                'final_local' => [
                    'action' => $finalAction,
                    'query' => $baseQuery()
                        ->where('status', 'completed')
                        ->where(function ($q) {
                            $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
                        })
                        ->whereNull('pra_status'),
                ],
            ];

            $deletedByKind = ['provisional' => 0, 'final_local' => 0];
            // Quota month bounds: deleted finals from the REPORT month still consume
            // that month's quota (PlanLimitService adds deleted_final_count back in).
            // Backlog bills from EARLIER months must NOT inflate the current month's
            // count — their quota month is already over.
            $monthStart = \Carbon\Carbon::parse($date)->startOfMonth();
            $monthEnd = \Carbon\Carbon::parse($date)->endOfMonth();
            foreach ($sets as $billKind => $set) {
                $rows = $set['query']->get();
                // Comprehensive Z-report detail (owner request Jul 2026): what was
                // washed, how much it was worth, and how much of it was backlog
                // from earlier un-closed dates.
                $localSummary[$billKind] = [
                    'action' => $set['action'],
                    'count' => $rows->count(),
                    'amount' => round((float) $rows->sum('total_amount'), 2),
                    'backlog' => $rows->filter(fn ($t) => $t->business_date && $t->business_date < $date)->count(),
                ];
                // AUTO-FINALIZE (Aug 2026): sweep already promoted what it could —
                // merge its numbers into the Z-report detail. Remaining rows here
                // are the leftovers (quota out / older month / drafts / races);
                // they are CARRIED, never archived or deleted.
                if ($billKind === 'provisional' && $set['action'] === 'finalize') {
                    $localSummary[$billKind] = array_merge($localSummary[$billKind], $finalizeSweep ?? []);
                    continue;
                }
                if ($rows->isEmpty()) {
                    continue;
                }
                // CARRY FORWARD: bills survive the wash exactly as they are —
                // still un-archived, still in F10, finalizable tomorrow. Summary
                // above already recorded the pending count for the Z-report.
                if ($set['action'] === 'carry') {
                    continue;
                }
                // Rider DELETE-guard: unsettled cash delivery bills are ARCHIVED
                // instead of deleted (khata proof survives). Settled / returned /
                // non-cash rider bills wash normally.
                if ($set['action'] === 'delete' && $riderGuardReady) {
                    $riderGuarded = $rows->filter(fn ($t) => $t->rider_id
                        && $t->payment_method === 'cash'
                        && !$t->rider_settlement_id
                        && $t->delivery_status !== 'returned');
                    if ($riderGuarded->isNotEmpty()) {
                        $archivedCount += PosTransaction::withoutGlobalScope('hide_archived')
                            ->whereIn('id', $riderGuarded->pluck('id')->all())
                            ->update([
                                'is_archived' => true,
                                'archived_at' => now(),
                                'archived_by_report_id' => $report->id,
                            ]);
                        $localSummary[$billKind]['rider_guarded'] = $riderGuarded->count();
                        $rows = $rows->reject(fn ($t) => $t->rider_id
                            && $t->payment_method === 'cash'
                            && !$t->rider_settlement_id
                            && $t->delivery_status !== 'returned');
                        if ($rows->isEmpty()) {
                            continue;
                        }
                    }
                }
                if ($set['action'] === 'delete') {
                    if ($spendPersist) {
                        $now = now();
                        $snapshots = $rows
                            ->filter(fn ($t) => $t->customer_id || !empty($t->customer_phone))
                            ->map(fn ($t) => [
                                'company_id' => $companyId,
                                'customer_id' => $t->customer_id,
                                'customer_phone' => $t->customer_phone,
                                'customer_name' => $t->customer_name,
                                'invoice_number' => $t->invoice_number,
                                'bill_kind' => $billKind,
                                'payment_method' => $t->payment_method,
                                'subtotal' => $t->subtotal,
                                'discount_amount' => $t->discount_amount,
                                'tax_amount' => $t->tax_amount,
                                'total_amount' => $t->total_amount,
                                'sold_at' => $t->created_at,
                                'dayclose_report_id' => $report->id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ])->values()->all();
                        if (!empty($snapshots)) {
                            \App\Models\PosCustomerSpendSnapshot::insert($snapshots);
                        }
                    }
                    $ids = $rows->pluck('id')->all();
                    \App\Models\PosTransactionItem::whereIn('transaction_id', $ids)->delete();
                    \App\Models\PosPayment::whereIn('transaction_id', $ids)->delete();
                    $kindDeleted = PosTransaction::withoutGlobalScope('hide_archived')
                        ->whereIn('id', $ids)->delete();
                    $deletedCount += $kindDeleted;
                    // Quota add-back counts ONLY report-month bills (see note above):
                    // backlog from earlier months is deleted but not re-counted.
                    // Filter by BUSINESS date: an after-midnight final (created
                    // Aug 1 00:30, business_date Jul 31) deleted during Jul 31's
                    // close must still be credited to July's quota — created_at
                    // bounds would let it escape quota entirely.
                    $deletedByKind[$billKind] += $rows
                        ->filter(fn ($t) => ($d = $t->business_date ?: $t->created_at?->toDateString())
                            && $d >= $monthStart->toDateString() && $d <= $monthEnd->toDateString())
                        ->count();
                } else {
                    $archivedCount += PosTransaction::withoutGlobalScope('hide_archived')
                        ->whereIn('id', $rows->pluck('id')->all())
                        ->update([
                            'is_archived' => true,
                            'archived_at' => now(),
                            'archived_by_report_id' => $report->id,
                        ]);
                }
            }

            // Persist deleted counts on the report: deleted reporting-OFF finals must
            // still consume monthly quota (PlanLimitService adds these back in), and
            // the Z-report PDF states how many bills were removed per policy.
            if ($deletedByKind['provisional'] > 0 || $deletedByKind['final_local'] > 0) {
                $report->forceFill([
                    'deleted_final_count' => $deletedByKind['final_local'],
                    'deleted_provisional_count' => $deletedByKind['provisional'],
                ])->save();
            }

            // Open held orders AT close time (ZFC 3 Aug 2026): stamped on the
            // report so both close paths surface them — manual close sees the
            // live warning on the page, the user-less AUTO close leaves this
            // durable record ("din band hua magar X tables khule the") on the
            // Z-report view. Informational only — the close never touches or
            // blocks on held orders. try/catch: reporting must never fail a close.
            try {
                $heldAtClose = $this->openHeldOrdersSummary($companyId, Company::find($companyId));
                if ($heldAtClose->count > 0) {
                    // key is 'orders' (not 'count') — the day-close view gates the
                    // wash section on sum('count') across local_summary entries.
                    $localSummary['open_orders_at_close'] = [
                        'orders' => $heldAtClose->count,
                        'tables' => $heldAtClose->tables,
                        'table_numbers' => $heldAtClose->tableNumbers,
                        'amount' => $heldAtClose->amount,
                    ];
                }
            } catch (\Throwable $e) {
                // never fail the close over the informational summary
            }

            // Comprehensive wash detail for the Z-report view/PDF. try/catch: the
            // local_summary column may not exist yet on a prod box mid-deploy
            // (schema-drift self-heal pattern) — the wash itself must never fail
            // because of a missing reporting column.
            try {
                $report->forceFill(['local_summary' => $localSummary])->save();
            } catch (\Throwable $e) {
                // column missing pre-migration — report simply has no wash detail
            }

            // Delivery Riders (Jul 2026): rider day detail on the Z-report. Same
            // schema-drift try/catch pattern — never fail the close over reporting.
            if (!empty($riderFigures['active'])) {
                try {
                    $report->forceFill(['rider_summary' => $riderFigures])->save();
                } catch (\Throwable $e) {
                    // rider_summary column missing pre-migration — skip detail
                }
            }
        });

        return ['status' => 'created', 'report' => $report, 'archived' => $archivedCount, 'deleted' => $deletedCount, 'report_number' => $reportNumber, 'summary' => $localSummary, 'finalize_sweep' => $finalizeSweep];
    }

    public function dayCloseReportPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $report = PosDayCloseReport::where('company_id', $companyId)->findOrFail($id);

        // Day-Close PDF shows HISTORICAL truth — include archived bills so the
        // closed-day report stays consistent even after rows were archived.
        // Local (non-PRA) bills excluded — visible only in the Local Bills Portal.
        $transactions = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('business_date', $report->report_date->toDateString())
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        $cashierBreakdown = $transactions->groupBy(fn($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            return (object) [
                'count' => $group->count(),
                'revenue' => $group->sum('total_amount'),
                'tax' => $group->sum('tax_amount'),
            ];
        });

        $analytics = $this->buildDayCloseAnalytics($companyId, $report->report_date->toDateString(), $transactions, $company);
        // Plan gate: hazri section is Pro+ (views already @if(!empty($hazri))).
        $hazri = PosFeatureService::planAllows($company, 'hazri_enabled')
            ? $this->buildHazriRows($companyId, $report->report_date->toDateString())
            : [];
        // Biometric punches — same plan gate as session hazri.
        $bioPunches = PosFeatureService::planAllows($company, 'hazri_enabled')
            ? $this->buildBiometricRows($companyId, $report->report_date->toDateString())
            : [];

        return $this->renderReportPdf(
            'pos.day-close-pdf',
            compact('company', 'report', 'transactions', 'cashierBreakdown', 'analytics', 'hazri', 'bioPunches'),
            "Day-Close-{$report->report_number}-{$report->report_date->format('Y-m-d')}.pdf"
        );
    }

    /**
     * 80mm thermal Z-report (owner request Jul 2026): browser-printable summary
     * of a CLOSED day for cheap receipt printers — same historical data set as
     * the A4 PDF (archived bills included, local bills excluded).
     */
    public function dayCloseThermal($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $report = PosDayCloseReport::where('company_id', $companyId)->findOrFail($id);

        $transactions = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('business_date', $report->report_date->toDateString())
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        $cashierBreakdown = $transactions->groupBy(fn ($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            return (object) [
                'count' => $group->count(),
                'revenue' => $group->sum('total_amount'),
                'tax' => $group->sum('tax_amount'),
            ];
        });

        $analytics = $this->buildDayCloseAnalytics($companyId, $report->report_date->toDateString(), $transactions, $company);
        $hazri = PosFeatureService::planAllows($company, 'hazri_enabled')
            ? $this->buildHazriRows($companyId, $report->report_date->toDateString())
            : [];
        // Biometric punches — same plan gate as session hazri.
        $bioPunches = PosFeatureService::planAllows($company, 'hazri_enabled')
            ? $this->buildBiometricRows($companyId, $report->report_date->toDateString())
            : [];

        return view('pos.day-close-thermal', compact('company', 'report', 'transactions', 'cashierBreakdown', 'analytics', 'hazri', 'bioPunches'));
    }

    /**
     * ═══ Staff Hazri (owner batch, 26 Jul 2026) ═══
     * Attendance report page — ADMIN/MANAGER-ONLY (cashiers & confined roles
     * kabhi staff ki hazri na dekhein). Data = pos_user_sessions (login/logout/
     * last-seen) + us business day ke bills (min/max sale time per user).
     *
     * Payroll range summary added Task #280: optional ?date_from=&date_to= params
     * build a per-staff aggregated total-duty-hours table across the range.
     */
    public function hazriReport(Request $request)
    {
        if ($r = $this->planGate('hazri_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();
        if (!$user->isPosAdmin()) {
            abort(403);
        }
        $company = Company::find($companyId);

        $date = $request->get('date');
        try {
            $date = $date ? \Carbon\Carbon::parse($date)->toDateString() : \App\Services\PosBusinessDay::current($companyId);
        } catch (\Throwable $e) {
            $date = \App\Services\PosBusinessDay::current($companyId);
        }

        $rows = $this->buildHazriRows($companyId, $date);
        // loadMissing = explicit load (lazy access is FATAL under preventLazyLoading).
        $opening = \App\Models\PosDayOpening::forDate($companyId, $date)?->loadMissing('enteredBy');

        // Biometric punches for this business day (4 Aug 2026).
        $bioPunches = $this->buildBiometricRows($companyId, $date);
        $hasBioDevices = \Illuminate\Support\Facades\Schema::hasTable('pos_biometric_devices')
            && \App\Models\PosBiometricDevice::where('company_id', $companyId)->exists();

        // Unmapped-PIN count (last 14 days) — drives the subtle badge on this page.
        $unmappedPinCount = 0;
        if ($hasBioDevices && \Illuminate\Support\Facades\Schema::hasTable('pos_biometric_punches')) {
            try {
                $unmappedPinCount = \App\Models\PosBiometricPunch::where('company_id', $companyId)
                    ->whereNull('user_id')
                    ->whereNotNull('device_pin')
                    ->where('punched_at', '>=', now()->subDays(14))
                    ->distinct('device_pin')
                    ->count('device_pin');
            } catch (\Throwable $e) {
                $unmappedPinCount = 0;
            }
        }

        // ── Payroll range summary (Task #280) ─────────────────────────────
        $rangeRows    = null;   // array of session-summary stdClass objects, or null = not requested
        $rangeBioRows = null;   // array of biometric-summary stdClass objects, or null = not requested
        $dateFrom     = null;
        $dateTo       = null;
        $rangeError   = null;

        if ($request->filled('date_from') && $request->filled('date_to')) {
            try {
                $dateFrom = \Carbon\Carbon::parse($request->get('date_from'))->toDateString();
                $dateTo   = \Carbon\Carbon::parse($request->get('date_to'))->toDateString();
            } catch (\Throwable $e) {
                $rangeError = __('pos.payroll_range_invalid');
                $dateFrom = $dateTo = null;
            }

            if ($dateFrom && $dateTo) {
                try {
                    [$rangeRows, $rangeBioRows] = $this->buildHazriRangeSummary($companyId, $dateFrom, $dateTo);
                } catch (\InvalidArgumentException $e) {
                    $rangeError = $e->getMessage() === 'range_too_long'
                        ? __('pos.payroll_range_too_long')
                        : ($e->getMessage() === 'range_future'
                            ? __('pos.payroll_range_future')
                            : __('pos.payroll_range_invalid'));
                    $dateFrom = $dateTo = null;
                } catch (\Throwable $e) {
                    \Log::warning('hazri range summary error: ' . $e->getMessage());
                    $rangeError = __('pos.payroll_range_invalid');
                    $dateFrom = $dateTo = null;
                }
            }
        }

        return view('pos.reports-hazri', compact(
            'company', 'date', 'rows', 'opening', 'bioPunches', 'hasBioDevices', 'unmappedPinCount',
            'rangeRows', 'rangeBioRows', 'dateFrom', 'dateTo', 'rangeError'
        ));
    }

    /**
     * Payroll PDF export — same gates as hazriReport.
     * GET /reports/hazri/payroll-pdf?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
     */
    public function payrollHazriPdf(Request $request)
    {
        if ($r = $this->planGate('hazri_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();
        if (!$user->isPosAdmin()) {
            abort(403);
        }
        $company = Company::find($companyId);

        $dateFrom = $request->get('date_from');
        $dateTo   = $request->get('date_to');

        try {
            $dateFrom = $dateFrom ? \Carbon\Carbon::parse($dateFrom)->toDateString() : null;
            $dateTo   = $dateTo   ? \Carbon\Carbon::parse($dateTo)->toDateString()   : null;
        } catch (\Throwable $e) {
            abort(400, __('pos.payroll_range_invalid'));
        }

        if (!$dateFrom || !$dateTo) {
            abort(400, 'Date range required.');
        }

        try {
            [$rangeRows, $rangeBioRows] = $this->buildHazriRangeSummary($companyId, $dateFrom, $dateTo);
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage() === 'range_too_long'
                ? __('pos.payroll_range_too_long')
                : ($e->getMessage() === 'range_future'
                    ? __('pos.payroll_range_future')
                    : __('pos.payroll_range_invalid'));
            abort(400, $msg);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'pos.reports-hazri-payroll-pdf',
            compact('company', 'dateFrom', 'dateTo', 'rangeRows', 'rangeBioRows')
        )->setPaper('a4', 'portrait');

        $filename = 'Payroll-Hazri-' . $dateFrom . '-to-' . $dateTo . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * ── Payroll range summary (Task #280) ──────────────────────────────────
     * Builds per-staff aggregated duty hours across a date range.
     *
     * Strategy: ONE bulk DB query per table (sessions, bills, biometric) for
     * the whole range, then group by business-day bucket (6 AM boundary) and
     * user/pin in PHP.  This keeps query count to 3 regardless of range length
     * (vs N-per-day loop).  The business-day cutoff per day is computed from
     * the pre-grouped bucket, so PosHazriDutyHours sees the correct cutoff.
     *
     * Returns [sessionSummary[], biometricSummary[]]
     * Throws \InvalidArgumentException('range_too_long' | 'range_future') on bad input.
     */
    private function buildHazriRangeSummary(int $companyId, string $from, string $to): array
    {
        $start = \Carbon\Carbon::parse($from);
        $end   = \Carbon\Carbon::parse($to);

        if ($end->lt($start)) {
            throw new \InvalidArgumentException('range_future');
        }
        if ($start->diffInDays($end) > 62) {
            throw new \InvalidArgumentException('range_too_long');
        }

        // Bulk window: $from 06:00 → ($to + 1 day) 06:00
        $rangeStart = \Carbon\Carbon::parse($from, config('app.timezone'))->setTime(6, 0);
        $rangeEnd   = \Carbon\Carbon::parse($to,   config('app.timezone'))->setTime(6, 0)->addDay();

        // ── 1. Fetch all sessions, bills, and biometric punches in one pass ──
        $allSessions = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('pos_user_sessions')) {
            $allSessions = \App\Models\PosUserSession::where('company_id', $companyId)
                ->where('login_at', '>=', $rangeStart)
                ->where('login_at', '<', $rangeEnd)
                ->orderBy('login_at')
                ->get();
        }

        $allBills = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereBetween('business_date', [$from, $to])
            ->selectRaw('created_by, business_date, COUNT(*) as bill_count, SUM(total_amount) as revenue')
            ->groupBy('created_by', 'business_date')
            ->get();

        $allPunches = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('pos_biometric_punches')) {
            $allPunches = \App\Models\PosBiometricPunch::where('company_id', $companyId)
                ->where('punched_at', '>=', $rangeStart)
                ->where('punched_at', '<', $rangeEnd)
                ->orderBy('punched_at')
                ->get();
        }

        // ── 2. Pre-fetch all users in a single query ─────────────────────────
        $allUserIds = $allSessions->pluck('user_id')
            ->merge($allBills->pluck('created_by'))
            ->merge($allPunches->pluck('user_id')->filter())
            ->unique()->filter()->values();
        $users = $allUserIds->isNotEmpty()
            ? User::where('company_id', $companyId)->whereIn('id', $allUserIds)->get()->keyBy('id')
            : collect();

        // ── 3. Bucket sessions by business date (subHours(6) maps 6AM–6AM → date) ──
        //  login_at 06:00 Aug 15 → sub 6h → 00:00 Aug 15 → "2024-08-15"  ✓
        //  login_at 05:59 Aug 15 → sub 6h → 23:59 Aug 14 → "2024-08-14"  ✓
        $sessionsByDay = [];    // ['YYYY-MM-DD' => ['user_id' => [sessions…]]]
        foreach ($allSessions as $s) {
            $biz = \Carbon\Carbon::parse($s->login_at, config('app.timezone'))->subHours(6)->toDateString();
            $sessionsByDay[$biz][$s->user_id][] = $s;
        }

        // ── 4. Per-staff session totals ───────────────────────────────────────
        $sessionTotals = [];  // user_id => stdClass aggregate

        foreach ($sessionsByDay as $bizDate => $byUser) {
            $cutoff = \Carbon\Carbon::parse($bizDate, config('app.timezone'))->setTime(6, 0)->addDay();
            foreach ($byUser as $uid => $sList) {
                $duty = \App\Support\PosHazriDutyHours::fromSessions(collect($sList), $cutoff);
                if (!isset($sessionTotals[$uid])) {
                    $u = $users->get($uid);
                    $sessionTotals[$uid] = (object)[
                        'user_id'       => $uid,
                        'name'          => $u?->name ?? ('#'.$uid),
                        'pos_role'      => $u ? ($u->pos_role ?: ($u->role === 'company_admin' ? 'pos_admin' : null)) : null,
                        'days_present'  => 0,
                        'total_minutes' => 0,
                        'any_open'      => false,
                        'total_bills'   => 0,
                        'total_revenue' => 0.0,
                    ];
                }
                $sessionTotals[$uid]->days_present++;
                $sessionTotals[$uid]->total_minutes += $duty->minutes;
                if ($duty->open) { $sessionTotals[$uid]->any_open = true; }
            }
        }

        // Merge bill totals (already grouped by user+business_date, just sum)
        foreach ($allBills as $b) {
            $uid = $b->created_by;
            if (isset($sessionTotals[$uid])) {
                $sessionTotals[$uid]->total_bills   += (int)   $b->bill_count;
                $sessionTotals[$uid]->total_revenue += (float) $b->revenue;
            }
        }

        usort($sessionTotals, fn($a, $b) => strcmp($a->name, $b->name));

        // ── 5. Bucket biometric punches by business date ─────────────────────
        $punchesByDay = [];   // ['YYYY-MM-DD' => ['u_N' | 'pin_X' => [punches…]]]
        foreach ($allPunches as $p) {
            $biz = \Carbon\Carbon::parse($p->punched_at, config('app.timezone'))->subHours(6)->toDateString();
            $key = $p->user_id ? 'u_'.$p->user_id : 'pin_'.($p->device_pin ?? 'unknown');
            $punchesByDay[$biz][$key][] = $p;
        }

        // ── 6. Per-staff biometric totals ─────────────────────────────────────
        $bioTotals = [];  // key => stdClass aggregate

        foreach ($punchesByDay as $bizDate => $byKey) {
            $cutoff = \Carbon\Carbon::parse($bizDate, config('app.timezone'))->setTime(6, 0)->addDay();
            foreach ($byKey as $key => $pList) {
                $duty = \App\Support\PosHazriDutyHours::fromPunches($pList, $cutoff);
                if (!isset($bioTotals[$key])) {
                    $first = $pList[0];
                    $u = $first->user_id ? $users->get($first->user_id) : null;
                    $bioTotals[$key] = (object)[
                        'user_id'       => $first->user_id,
                        'device_pin'    => $first->device_pin,
                        'name'          => $u?->name,
                        'days_present'  => 0,
                        'total_minutes' => 0,
                        'any_open'      => false,
                    ];
                }
                $bioTotals[$key]->days_present++;
                $bioTotals[$key]->total_minutes += $duty->minutes;
                if ($duty->open) { $bioTotals[$key]->any_open = true; }
            }
        }

        usort($bioTotals, fn($a, $b) => strcmp(
            $a->name ?? ($a->device_pin ?? ''),
            $b->name ?? ($b->device_pin ?? '')
        ));

        return [array_values($sessionTotals), array_values($bioTotals)];
    }

    /**
     * Hazri rows for one BUSINESS day (6 AM → next 6 AM window, wahi rule jo
     * PosBusinessDay/auto-dayclose ka hai). Ek row per staff member:
     * pehla login, aakhri logout (ya last-seen jab logout kabhi dabaya hi
     * nahi), session count, bills + pehli/aakhri sale. Table na ho (prod
     * migrate pending) to khali array — report/day-close kabhi na toote.
     */
    private function buildHazriRows(int $companyId, string $date): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_user_sessions')) {
                return [];
            }
            $start = \Carbon\Carbon::parse($date, config('app.timezone'))->setTime(6, 0);
            $end = $start->copy()->addDay();

            $sessions = \App\Models\PosUserSession::where('company_id', $companyId)
                ->where('login_at', '>=', $start)
                ->where('login_at', '<', $end)
                ->orderBy('login_at')
                ->get()
                ->groupBy('user_id');

            // Bills of the SAME business day (historical truth — archived
            // rows included, matches the day-close data set).
            $bills = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('business_date', $date)
                ->selectRaw('created_by, COUNT(*) as bill_count, MIN(created_at) as first_sale, MAX(created_at) as last_sale, SUM(total_amount) as revenue')
                ->groupBy('created_by')
                ->get()
                ->keyBy('created_by');

            $userIds = $sessions->keys()->merge($bills->keys())->unique()->filter()->values();
            if ($userIds->isEmpty()) {
                return [];
            }
            $users = User::where('company_id', $companyId)->whereIn('id', $userIds)->get()->keyBy('id');

            $rows = [];
            foreach ($userIds as $uid) {
                $u = $users->get($uid);
                if (!$u) {
                    continue; // deleted/foreign user — skip silently
                }
                $s = $sessions->get($uid, collect());
                $b = $bills->get($uid);
                $openSession = $s->firstWhere('logout_at', null);
                $lastSeen = $s->map(fn ($x) => $x->last_activity_at ?? $x->logout_at ?? $x->login_at)->filter()->max();
                $duty = \App\Support\PosHazriDutyHours::fromSessions($s, $end);
                $rows[] = (object) [
                    'user_id' => $uid,
                    'name' => $u->name,
                    'pos_role' => $u->pos_role ?: ($u->role === 'company_admin' ? 'pos_admin' : null),
                    'first_in' => $s->min('login_at'),
                    'last_out' => $openSession ? null : $s->map(fn ($x) => $x->logout_at)->filter()->max(),
                    'last_seen' => $lastSeen,
                    'still_open' => (bool) $openSession,
                    'session_count' => $s->count(),
                    'bill_count' => $b ? (int) $b->bill_count : 0,
                    'revenue' => $b ? (float) $b->revenue : 0.0,
                    'first_sale' => $b?->first_sale,
                    'last_sale' => $b?->last_sale,
                    'duty_minutes' => $duty->minutes,
                    'duty_open'    => $duty->open,
                ];
            }

            // Pehle jo pehle aaya (first_in), bina-login (sirf bills) sab se aakhir.
            usort($rows, function ($a, $b) {
                if ($a->first_in && $b->first_in) return $a->first_in <=> $b->first_in;
                if ($a->first_in) return -1;
                if ($b->first_in) return 1;
                return strcmp($a->name, $b->name);
            });

            return $rows;
        } catch (\Throwable $e) {
            \Log::warning('hazri rows failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Biometric punch rows for one BUSINESS day (same 6 AM → 6 AM window).
     * Returns one row per staff member (or unmapped PIN) with first check-in,
     * last check-out, total punch count, and source (adms / csv_import).
     * Returns empty array when table is missing or on any error.
     */
    private function buildBiometricRows(int $companyId, string $date): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_biometric_punches')) {
                return [];
            }
            $start = \Carbon\Carbon::parse($date, config('app.timezone'))->setTime(6, 0);
            $end   = $start->copy()->addDay();

            $punches = \App\Models\PosBiometricPunch::where('company_id', $companyId)
                ->where('punched_at', '>=', $start)
                ->where('punched_at', '<', $end)
                ->orderBy('punched_at')
                ->get();

            if ($punches->isEmpty()) {
                return [];
            }

            // Resolve user names for mapped punches
            $userIds = $punches->pluck('user_id')->filter()->unique();
            $users   = User::whereIn('id', $userIds)->get()->keyBy('id');

            // Group by user_id (for mapped) or device_pin (for unmapped)
            $groups = [];
            foreach ($punches as $p) {
                $key = $p->user_id ? 'u_' . $p->user_id : 'pin_' . ($p->device_pin ?? 'unknown');
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'user_id'    => $p->user_id,
                        'device_pin' => $p->device_pin,
                        'punches'    => [],
                    ];
                }
                $groups[$key]['punches'][] = $p;
            }

            $rows = [];
            foreach ($groups as $g) {
                $ps       = $g['punches'];
                $user     = $g['user_id'] ? $users->get($g['user_id']) : null;
                $ins      = array_values(array_filter($ps, fn ($p) => $p->punch_type === 'check_in'));
                $outs     = array_values(array_filter($ps, fn ($p) => $p->punch_type === 'check_out'));
                $firstIn  = collect($ins)->min('punched_at');
                $lastOut  = collect($outs)->max('punched_at');
                $sources  = collect($ps)->pluck('source')->unique()->values()->all();

                $duty = \App\Support\PosHazriDutyHours::fromPunches($ps, $end);

                $rows[] = (object) [
                    'user_id'      => $g['user_id'],
                    'name'         => $user?->name,
                    'device_pin'   => $g['device_pin'],
                    'first_in'     => $firstIn,
                    'last_out'     => $lastOut,
                    'in_count'     => count($ins),
                    'out_count'    => count($outs),
                    'total'        => count($ps),
                    'sources'      => $sources,
                    'duty_minutes' => $duty->minutes,
                    'duty_open'    => $duty->open,
                ];
            }

            usort($rows, function ($a, $b) {
                if ($a->first_in && $b->first_in) {
                    return $a->first_in <=> $b->first_in;
                }
                if ($a->first_in) return -1;
                if ($b->first_in) return 1;
                return strcmp($a->name ?? $a->device_pin, $b->name ?? $b->device_pin);
            });

            return $rows;
        } catch (\Throwable $e) {
            \Log::warning('biometric rows failed: ' . $e->getMessage());
            return [];
        }
    }
}
