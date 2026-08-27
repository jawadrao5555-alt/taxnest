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
use App\Services\AuditLogService;
use App\Services\PraIntegrationService;
use App\Services\PosFeatureService;
use App\Services\PosPlanComparisonService;
use App\Support\PosPaymentBuckets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /**
     * Dark mode (owner video, 25 Aug 2026): "sale screen par dark mode on karta
     * hoon, dashboard par jata hoon to khatam". The Ctrl+K palette only flipped
     * the <html> class in the browser — nothing was stored, so the very next
     * page load re-rendered from users.dark_mode (still off) and the choice
     * vanished on every navigation. The layout already renders the class from
     * that column, so the fix is to persist the user's pick here.
     *
     * Per-USER preference (not a shop setting) — a cashier may set their own,
     * hence no isPosCashier guard and no /settings/ prefix.
     *
     * Saving the row also bumps users.updated_at, which is hashed into the sale
     * screen's boot fingerprint — an SW-cached copy of the sale screen notices
     * and refreshes itself instead of staying light forever.
     */
    public function toggleDarkMode(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => __('pos.setting_save_failed')], 401);
        }

        $dark = $request->has('dark')
            ? $request->boolean('dark')
            : !$user->dark_mode;

        $user->dark_mode = $dark;
        $user->save();

        return response()->json(['success' => true, 'dark' => (bool) $dark]);
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
     * Cash Received / Wapsi (change-due) box — per-company OPT-IN toggle
     * (owner, Aug 2026: "koi company chahti hai koi nahi" — so it's a setting,
     * default OFF). ON = Pay modal shows the Cash Received input + 500/1000/5k
     * quick notes + "Wapas dein" line, and the receipt popup / printed change
     * block that rides on the entered value. OFF = sale screen stays exactly
     * as before (backend cash_received/change_due columns keep working for
     * historical rows either way).
     */
    public function toggleCashReceived(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        // Prod schema drift guard — never pretend to save into a missing column.
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_cash_received_enabled')) {
            return response()->json(['success' => false, 'message' => __('pos.setting_not_available_yet')], 503);
        }
        $enabled = $request->boolean('enabled');
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_cash_received_enabled' => $enabled]);
        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    /**
     * Task 1036 — WhatsApp Bill settings (owner voice note 17 Aug 2026).
     * Two flags on one endpoint (send only the key you're flipping):
     *  - enabled:   receipt popup par WhatsApp Bill button (default ON)
     *  - auto_open: final bill ban'te hi WhatsApp window khud khule (default OFF;
     *               popup-block hone par button highlighted fallback rehta hai).
     */
    public function toggleWhatsappBill(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        // Prod schema drift guard — never pretend to save into a missing column.
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_whatsapp_bill_enabled')
            || !\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_whatsapp_bill_auto_open')) {
            return response()->json(['success' => false, 'message' => __('pos.setting_not_available_yet')], 503);
        }
        $update = [];
        if ($request->has('enabled')) {
            $update['pos_whatsapp_bill_enabled'] = $request->boolean('enabled');
        }
        if ($request->has('auto_open')) {
            $update['pos_whatsapp_bill_auto_open'] = $request->boolean('auto_open');
        }
        if (!$update) {
            return response()->json(['success' => false, 'message' => __('pos.setting_save_failed')], 422);
        }
        $companyId = app('currentCompanyId');
        // Pro+ plan gate (owner, 17 Aug 2026): turning anything ON needs the
        // plan; turning OFF is always allowed.
        $turningOn = ($update['pos_whatsapp_bill_enabled'] ?? false)
            || ($update['pos_whatsapp_bill_auto_open'] ?? false);
        if ($turningOn && !\App\Services\PosFeatureService::planAllows(Company::find($companyId), 'whatsapp_enabled')) {
            return response()->json(['success' => false, 'message' => __('pos.wa_bill_plan_locked_api')], 403);
        }
        Company::where('id', $companyId)->update($update);
        return response()->json(['success' => true]);
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
                'rp_receipt_theme' => 'nullable|in:' . implode(',', \App\Support\PosReceiptThemes::keys()),
                'rp_kot_theme' => 'nullable|in:' . implode(',', \App\Support\PosKotThemes::keys()),
                'rp_pdf_paper' => 'nullable|in:thermal,a4',
                'rp_order_match' => 'nullable|in:off,token,code',
                'rp_pra_number_style' => 'nullable|in:serial,token',
                'rp_local_number_style' => 'nullable|in:serial,token',
                'rp_delivery_receipt_present' => 'nullable|in:1',
                'rp_delivery_receipt_on_assign' => 'nullable|in:1',
            ]);
            $prefs = $company->invoice_display_prefs ?? [];
            // Stale-form guard, per display set (Task 1377 — owner 21 Aug 2026).
            // Each block below is a WHOLESALE rewrite driven by checkbox presence,
            // so a POST from an outdated copy of this page (the service worker used
            // to runtime-cache /pos/receipt-settings) silently wiped every toggle it
            // did not carry: a form rendered before the Local tab shipped saved
            // pos_local as all-false, and the local bill lost its tax line even
            // though nobody had unticked it. Same class of bug the
            // rp_pos_style_present marker already fixed for pos_style.
            // A set is rewritten only when THIS request actually carries it — the
            // hidden marker (fresh form) or any of that set's own fields (scripted
            // and legacy POSTs keep working). Otherwise the stored set is left
            // exactly as it is; wiping is never the safe default.
            $rpPresent = $request->has('rp_present') || $request->hasAny([
                'rp_footer_text', 'rp_show_address', 'rp_show_ntn', 'rp_show_email',
                'rp_show_mobile', 'rp_show_cashier', 'rp_show_footer',
                'rp_show_business_name', 'rp_show_developed_by', 'rp_show_verify_line',
                'rp_show_tax',
            ]);
            $lpPresent = $request->has('lp_present') || $request->hasAny([
                'lp_footer_text', 'lp_show_address', 'lp_show_ntn', 'lp_show_email',
                'lp_show_mobile', 'lp_show_cashier', 'lp_show_footer',
                'lp_show_business_name', 'lp_show_developed_by', 'lp_show_tax',
            ]);
            // PRA (fiscal) receipt set — legacy 'pos' key, backward compatible.
            if ($rpPresent) {
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
                    // show_verify_line (Aug 2026): "Scan with PRA Sahulat App" under the QR.
                    // Checkbox present = ON; absent = OFF. Default ON matches legacy behaviour.
                    'show_verify_line' => $request->has('rp_show_verify_line'),
                ];
            }
            // Local (L-series) receipt set — owner request Jul 2026: PRA and Local
            // bills each get their OWN full display set (incl. its own show_tax).
            if ($lpPresent) {
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
            }
            // Print Style (Pizza Master Jul 2026): GLOBAL like paper size — bold
            // whole-receipt font + logo size/placement. Applies to both bill types.
            // Receipt Themes (Task 712): the form now submits a named theme
            // (rp_receipt_theme) that PosReceiptThemes maps onto the SAME
            // bold/logo keys. Re-saving the already-active theme is a no-op on
            // the stored pair (plain opt-out shops keep their exact combo).
            // Legacy fallback: an old cached form (or a scripted POST) that
            // still sends rp_style_bold/rp_logo_style keeps working; a POST
            // with NEITHER present leaves the company's current pair untouched.
            $curStyle = $company->posReceiptStyle();
            $theme = $request->input('rp_receipt_theme');
            if (\App\Support\PosReceiptThemes::isValid($theme)) {
                $styleBoldLogo = \App\Support\PosReceiptThemes::apply($theme, $curStyle);
            } elseif ($request->filled('rp_logo_style') || $request->has('rp_style_bold')) {
                $styleBoldLogo = [
                    'bold' => $request->has('rp_style_bold'),
                    'logo' => $request->input('rp_logo_style', 'side') === 'center' ? 'center' : 'side',
                ];
            } else {
                $styleBoldLogo = [
                    'bold' => (bool) ($curStyle['bold'] ?? true),
                    'logo' => ($curStyle['logo'] ?? 'center') === 'side' ? 'side' : 'center',
                ];
            }
            // bold/logo: always read-modify-write via posReceiptStyle() so a bare/stale
            // POST never silently resets them (e.g. theme-picker not in old cached form).
            $prefs['pos_style'] = [
                'bold' => $styleBoldLogo['bold'],
                'logo' => $styleBoldLogo['logo'],
            ];
            // Checkbox-based pos_style keys (show_logo, logo_finals_only, show_menu_qr)
            // and pdf_paper are only written when rp_pos_style_present is in the request,
            // meaning the form was freshly rendered and the user's intent is known.
            // A stale/cached form without the marker must never silently reset these to
            // their unchecked defaults — mirrors the rp_verify_present guard on the FBR page.
            if ($request->has('rp_pos_style_present')) {
                // PDF Download Paper (customer video Jul 2026): 'thermal' = exact
                // roll-width PDF page (default); 'a4' = real A4 page, receipt strip
                // top-left — fixes right-shifted/clipped prints on office printers.
                $prefs['pos_style']['pdf_paper'] = $request->input('rp_pdf_paper') === 'a4' ? 'a4' : 'thermal';
                // show_logo (Task #292): master logo toggle. Default ON (checkbox
                // present = on; absent = off). When off, logo never prints on any receipt.
                $prefs['pos_style']['show_logo'] = $request->has('rp_show_logo');
                // Logo on finals only: sub-option under show_logo — when ON, logo prints
                // only on final/PRA bills; suppressed on local/provisional bills.
                $prefs['pos_style']['logo_finals_only'] = $request->has('rp_logo_finals_only');
                // show_menu_qr (Task #292): master QR toggle. When off, neither the
                // Menu QR nor the invoice JSON fallback QR prints. PRA fiscal QR unaffected.
                $prefs['pos_style']['show_menu_qr'] = $request->has('rp_show_menu_qr');
            } else {
                // Stale form — preserve whatever is currently stored.
                // $curStyle does not carry pdf_paper (posReceiptStyle() omits it) and
                // $prefs['pos_style'] has already been overwritten with just bold+logo,
                // so read pdf_paper directly from the company's persisted prefs.
                $origPrefsStyle = $company->invoice_display_prefs ?? [];
                $origStyle = is_array($origPrefsStyle['pos_style'] ?? null)
                    ? $origPrefsStyle['pos_style'] : [];
                $prefs['pos_style']['pdf_paper']        = $origStyle['pdf_paper']       ?? 'thermal';
                $prefs['pos_style']['show_logo']        = $curStyle['show_logo']         ?? true;
                $prefs['pos_style']['logo_finals_only'] = $curStyle['logo_finals_only']  ?? false;
                $prefs['pos_style']['show_menu_qr']     = $curStyle['show_menu_qr']      ?? true;
            }
            $companyUpdates = [
                'invoice_display_prefs' => $prefs,
                // Paper size (owner request Jul 2026): same column PRA Settings writes —
                // last save from either page wins. Missing/invalid input keeps 80mm default.
                'receipt_printer_size' => $request->input('rp_printer_size', $company->receipt_printer_size ?? '80mm'),
            ];
            // Owner decision (Jul 2026): tax display toggle lives HERE (receipt
            // customization), not on the Features page. OFF = customer copy
            // shows grand TOTAL only; tax is always submitted to PRA in full.
            // Since the PRA/Local split this column is the PRA-receipt tax toggle.
            // Task 1377: it belongs to the PRA set, so it follows the same
            // stale-form guard — a POST that never carried the PRA panel must not
            // silently switch the fiscal receipt's tax lines off.
            if ($rpPresent) {
                $companyUpdates['pos_receipt_show_tax'] = $request->has('rp_show_tax');
            }
            // Print Position + Left Margin (Pizza Master, 11 Aug 2026): receipts now
            // have their OWN columns (receipt_align_center / receipt_left_margin_mm),
            // separate from the KOT's kot_* pair — fixing one printer no longer
            // shifts the other. Legacy fallback: if the new columns are missing
            // (PROD drift), keep writing the old shared kot_* pair like before.
            if ($request->filled('rp_align_center')) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'receipt_align_center')) {
                    $companyUpdates['receipt_align_center'] = (bool) ((int) $request->input('rp_align_center'));
                } elseif (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_align_center')) {
                    $companyUpdates['kot_align_center'] = (bool) ((int) $request->input('rp_align_center'));
                }
            }
            if ($request->filled('rp_left_margin_mm')) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'receipt_left_margin_mm')) {
                    $companyUpdates['receipt_left_margin_mm'] = max(0, min(30, (int) $request->input('rp_left_margin_mm')));
                } elseif (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_left_margin_mm')) {
                    $companyUpdates['kot_left_margin_mm'] = max(0, min(30, (int) $request->input('rp_left_margin_mm')));
                }
            }
            // KOT theme preset (Task 716): the form now submits a named preset
            // (rp_kot_theme) that PosKotThemes maps onto the SAME kot_compact +
            // kot_align_center columns. Re-saving the already-active preset is a
            // no-op on the stored pair (a shop's exact combo — e.g. compact AND
            // centered set from kitchen-settings — survives a settings re-save).
            // Legacy fallback: an old cached form (or scripted POST) that still
            // sends rp_kot_align_center / rp_kot_compact keeps working; a POST
            // with none of these leaves the company's current pair untouched.
            $kotTheme = $request->input('rp_kot_theme');
            if (\App\Support\PosKotThemes::isValid($kotTheme)) {
                $kotPair = \App\Support\PosKotThemes::apply($kotTheme, [
                    'compact' => (bool) ($company->kot_compact ?? false),
                    // Task 718: RAW nullable align — NULL = center default, so
                    // re-saving the pre-selected Center card is a no-op and picking
                    // Khula counts as an ACTIVE switch (writes explicit false).
                    'align'   => $company->kot_align_center,
                ]);
                if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_compact')) {
                    $companyUpdates['kot_compact'] = $kotPair['compact'];
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_align_center')) {
                    $companyUpdates['kot_align_center'] = $kotPair['align'];
                }
            } elseif (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_align_center')
                && $request->filled('rp_kot_align_center')) {
                $companyUpdates['kot_align_center'] = (bool) ((int) $request->input('rp_kot_align_center'));
            }
            // Receipt fallback guard (Task 718): receipt_80mm/58mm + proof-bill fall
            // back to kot_align_center while receipt_align_center is NULL. If this
            // save writes kot_align_center WITHOUT also writing the receipt column
            // (partial/cached form — the full page always submits rp_align_center),
            // freeze the receipt position at its current effective value first:
            // a KOT preset switch must only move the KOT, never the customer receipt.
            if (array_key_exists('kot_align_center', $companyUpdates)
                && !array_key_exists('receipt_align_center', $companyUpdates)
                && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'receipt_align_center')
                && $company->receipt_align_center === null) {
                $companyUpdates['receipt_align_center'] = (bool) ($company->kot_align_center ?? false);
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_left_margin_mm')
                && $request->filled('rp_kot_left_margin_mm')) {
                $companyUpdates['kot_left_margin_mm'] = max(0, min(30, (int) $request->input('rp_kot_left_margin_mm')));
            }
            // Dine-in FINAL auto-print (Pizza Master, 11 Aug 2026): OFF = payment par
            // dine-in ka final receipt KHUD print nahi hota (proof bill pehle diya ja
            // chuka hota hai). hidden=0/checkbox=1 pattern → has() check. Sale screen
            // yeh flag bake karti hai — column fingerprint list mein shamil hai.
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'print_on_pay_dinein')
                && $request->has('rp_dinein_autoprint')) {
                $companyUpdates['print_on_pay_dinein'] = (bool) $request->input('rp_dinein_autoprint');
            }
            // Delivery receipt default is owned by the shop, not whichever
            // browser last used the sale screen. A fresh marker is required so
            // an older cached receipt-settings form can never disable it.
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'delivery_receipt_print_on_assign')
                && $request->has('rp_delivery_receipt_present')) {
                $companyUpdates['delivery_receipt_print_on_assign'] = $request->boolean('rp_delivery_receipt_on_assign');
            }
            // KOT Print Style toggles (Aug 2026): also saveable from receipt-settings
            // so shops without the kitchen module can still control their KOT layout.
            // Uses rp_kot_* prefix + hidden=0/checkbox=1 pattern (same as kitchen-settings).
            // Task 716: kot_compact ab theme preset se aata hai — legacy
            // rp_kot_compact sirf tab mana jata hai jab koi valid theme na ho
            // (warna purana cached form theme ka faisla ulat deta).
            foreach (['kot_compact', 'kot_show_customer', 'kot_show_orderby', 'kot_show_barcode', 'kot_show_footer', 'kot_show_kitchen_notes'] as $kotFlag) {
                if ($kotFlag === 'kot_compact' && \App\Support\PosKotThemes::isValid($kotTheme)) {
                    continue;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('companies', $kotFlag)
                    && $request->has('rp_' . $kotFlag)) {
                    $companyUpdates[$kotFlag] = (bool) $request->input('rp_' . $kotFlag);
                }
            }
            // Order Matching (Aug 2026): same number on receipt + kitchen KOT.
            // off = nothing extra; token = daily token; code = unique ORD short code.
            // hasColumn guard: PROD drift self-heal convention + minimal test schemas.
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'order_match_style')
                && in_array($request->input('rp_order_match'), ['off', 'token', 'code'], true)) {
                $companyUpdates['order_match_style'] = $request->input('rp_order_match');
                // Task 662: manual save = deliberate choice — lock it so future
                // bulk rollout migrations (which must WHERE locked=false) can
                // never override this shop's pick again.
                if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'order_match_style_locked')) {
                    $companyUpdates['order_match_style_locked'] = true;
                }
            }
            // Bill Number Style (07 Aug 2026): per-stream receipt numbering display —
            // 'serial' = chalti series (POS-YYYY-NNNNN / L-NNN), 'token' = roz ka
            // token (1,2,3… business-day 6AM reset). Serial ALWAYS stays underneath
            // (khata/search/returns/PRA), token sirf receipt par numaya hota hai.
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pra_number_style')
                && in_array($request->input('rp_pra_number_style'), ['serial', 'token'], true)) {
                $companyUpdates['pra_number_style'] = $request->input('rp_pra_number_style');
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'local_number_style')
                && in_array($request->input('rp_local_number_style'), ['serial', 'token'], true)) {
                $companyUpdates['local_number_style'] = $request->input('rp_local_number_style');
            }
            $company->update($companyUpdates);
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
                // Task 1194: KOT-family picks may arrive union-encoded
                // ("uid::name" — uid ≤64 + '::' + name ≤255), hence the wider cap.
                'kot_printer' => 'nullable|string|max:340',
                'counter_kot_printer' => 'nullable|string|max:340',
                'counter_kot_enabled' => 'nullable|boolean',
                'print_confirm_ask' => 'nullable|boolean',
            ]);

            $settings = $company->printerSettings();
            $known = collect($settings['available_printers'])->pluck('name')->all();

            // Stale-form guard (Task 1393 — same shape as the PRA Receipt Settings
            // page, Task 1377). Every value below is rebuilt wholesale from what the
            // request happens to carry, so a POST that never carried this form at all
            // silently unset the printers and switched the tick-boxes OFF. Unchecked
            // checkboxes send nothing, so "form absent" and "everything unticked" look
            // identical on the wire — only the marker can tell them apart.
            //
            // The WHOLE form is ONE block here, deliberately: PosCounterKotGuardTest
            // locks the rule that a real printer-settings POST which omits the Counter
            // KOT fields means the admin cleared them. So the fallback spans every
            // field of the form (scripted and legacy POSTs keep working), and only a
            // request carrying none of them leaves the stored settings alone.
            $psPresent = $request->has('ps_present') || $request->hasAny([
                'silent_print_enabled', 'print_confirm_ask', 'counter_kot_enabled',
                'receipt_printer', 'kot_printer', 'counter_kot_printer',
            ]);

            if ($psPresent) {
                // Only accept printers the agent actually reported (or blank = unset).
                $receipt = trim((string) ($validated['receipt_printer'] ?? ''));
                $settings['receipt_printer'] = ($receipt !== '' && in_array($receipt, $known, true)) ? $receipt : null;
                // Task 1194 — KOT-family picks ride the UNION picker: a value may
                // carry its owning counter ("uid::name", validated against THAT
                // device's own reported list) or stay a legacy plain name (company-
                // wide list check, exactly as before). Invalid = silent unset,
                // same rule the plain names always had.
                $kotPick = \App\Models\PosAgentDevice::resolvePick($company, $validated['kot_printer'] ?? '');
                $settings['kot_printer'] = $kotPick['valid'] ? $kotPick['name'] : null;
                $settings['kot_printer_device'] = $kotPick['valid'] ? $kotPick['device_uid'] : null;
                // Counter KOT Copy (dine-in only): printer + its own ON/OFF tick.
                $counterPick = \App\Models\PosAgentDevice::resolvePick($company, $validated['counter_kot_printer'] ?? '');
                $settings['counter_kot_printer'] = $counterPick['valid'] ? $counterPick['name'] : null;
                $settings['counter_kot_printer_device'] = $counterPick['valid'] ? $counterPick['device_uid'] : null;
                $settings['counter_kot_enabled'] = $request->boolean('counter_kot_enabled') && $settings['counter_kot_printer'];
            }

            // Task 1166 — per-counter devices: persist the multi-counter section
            // BEFORE the master eligibility check so a shop configured ONLY with
            // per-counter printers (no company-wide pick) can still enable silent
            // printing in the same save. Validated per-device; no-op on legacy
            // schemas / single-counter shops (form posts nothing).
            $this->savePrinterDeviceSettings($request, $companyId);

            // Master toggle needs at least ONE real print target: a company-wide
            // receipt/KOT printer, or any counter with its own receipt printer
            // (multi-counter shops may not set a company default at all).
            $hasDevicePrinter = false;
            if (\App\Http\Controllers\AgentController::deviceRoutingReady()) {
                try {
                    $hasDevicePrinter = \App\Models\PosAgentDevice::where('company_id', $companyId)
                        ->whereNotNull('receipt_printer')
                        ->exists();
                } catch (\Throwable $e) {
                    $hasDevicePrinter = false;
                }
            }
            if ($psPresent) {
                $settings['silent_print_enabled'] = $request->boolean('silent_print_enabled')
                    && ($settings['receipt_printer'] || $settings['kot_printer'] || $hasDevicePrinter);
                // Task 565: opt-in Yes/No print-confirm dialog — independent of the
                // silent-print master (works for iframe/popup shops too).
                $settings['print_confirm_ask'] = $request->boolean('print_confirm_ask');
            }
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

        // Task 1166 — multi-counter registry (empty collections on legacy schema
        // or single-counter shops whose agent predates device identity).
        $devices = collect();
        $assignableTeam = collect();
        if (\App\Http\Controllers\AgentController::deviceRoutingReady()) {
            $devices = \App\Models\PosAgentDevice::where('company_id', $companyId)
                ->orderByDesc('last_seen_at')
                ->get();
            if ($devices->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_device_uid')) {
                // Everyone who can press Print on a bill: owner/admins, managers,
                // cashiers. Kitchen/waiter/rider roles never create bill jobs.
                $assignableTeam = User::where('company_id', $companyId)
                    ->where(function ($q) {
                        $q->where('role', 'company_admin')
                          ->orWhereIn('pos_role', ['pos_admin', 'pos_manager', 'pos_cashier']);
                    })
                    ->orderByRaw("CASE WHEN pos_role = 'pos_admin' OR role = 'company_admin' THEN 0 WHEN pos_role = 'pos_manager' THEN 1 ELSE 2 END")
                    ->orderBy('name')
                    ->get();
            }
        }

        // Task 1194 — union KOT-family picker options (every counter's printers,
        // counter-labeled). Single-counter/legacy shops get today's list back.
        $kotOptions = \App\Models\PosAgentDevice::kotPrinterOptions($company);

        return view('pos.printer-settings', compact('company', 'settings', 'agentOnline', 'recentFailed', 'devices', 'assignableTeam', 'kotOptions'));
    }

    /**
     * Task 1166 — persist the multi-counter section of the Printer Settings
     * form: device_receipt_printer[uid], device_name[uid], user_device[userId].
     * Every value is validated against THIS company's registered devices and
     * each device's own reported printer list — a printer another counter
     * reported can never be saved onto this one.
     */
    private function savePrinterDeviceSettings(Request $request, int $companyId): void
    {
        if (!\App\Http\Controllers\AgentController::deviceRoutingReady()) {
            return;
        }
        $devices = \App\Models\PosAgentDevice::where('company_id', $companyId)->get()->keyBy('device_uid');
        if ($devices->isEmpty()) {
            return;
        }

        $printerPicks = (array) $request->input('device_receipt_printer', []);
        $names = (array) $request->input('device_name', []);
        foreach ($devices as $uid => $device) {
            $dirty = [];
            if (array_key_exists($uid, $printerPicks)) {
                $pick = trim((string) $printerPicks[$uid]);
                $own = collect($device->printers ?? [])->pluck('name')->all();
                $dirty['receipt_printer'] = ($pick !== '' && in_array($pick, $own, true)) ? $pick : null;
            }
            if (array_key_exists($uid, $names)) {
                $name = mb_substr(trim((string) $names[$uid]), 0, 60);
                $dirty['name'] = $name !== '' ? $name : null;
            }
            if ($dirty) {
                $device->update($dirty);
            }
        }

        if ($request->has('user_device') && \Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_device_uid')) {
            foreach ((array) $request->input('user_device', []) as $userId => $uid) {
                if (!is_numeric($userId)) {
                    continue;
                }
                $member = User::where('company_id', $companyId)->find((int) $userId);
                if (!$member) {
                    continue;
                }
                $uid = trim((string) $uid);
                $member->pos_device_uid = ($uid !== '' && $devices->has($uid)) ? $uid : null;
                $member->save();
            }
        }
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
     * Task 1166 — resolve the pressing user's assigned counter for bill/proof
     * silent prints. Returns ['device_uid' => ..., 'printer' => ...] ONLY when
     * every link in the chain holds:
     *   user has an assignment → device row exists (this company) → agent on
     *   that PC heartbeat within 2 min → admin picked that counter's receipt
     *   printer. Otherwise null = company-wide behavior (today's path), so a
     *   bill can never be stamped for a counter that cannot print it.
     * Schema-guarded: pre-migration prod short-circuits to null.
     */
    private function resolveUserPrintDevice($user, int $companyId): ?array
    {
        try {
            if (!\App\Http\Controllers\AgentController::deviceRoutingReady()
                || !\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_device_uid')) {
                return null;
            }
            $uid = $user->pos_device_uid ?? null;
            if (!$uid) {
                return null;
            }
            $device = \App\Models\PosAgentDevice::where('company_id', $companyId)
                ->where('device_uid', $uid)
                ->first();
            if (!$device || !$device->isOnline() || !$device->receipt_printer) {
                return null;
            }
            return ['device_uid' => $device->device_uid, 'printer' => $device->receipt_printer];
        } catch (\Throwable $e) {
            return null; // routing must never break the print fallback chain
        }
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
            // Task 753: 'last' = MISSED-DELTA RECOVERY reprint (akhri KOT batch
            // + still-unprinted rows) — explicit rescue when the slip never
            // physically came out of the printer.
            'batch' => 'nullable|in:last',
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

        // Task 1166 — per-counter routing (bill + proof only; KOT/kitchen
        // routing is deliberately untouched): if the pressing cashier is
        // assigned to a counter whose agent is ONLINE and has its own receipt
        // printer, stamp the job for that counter. Anything short of that
        // (no assignment, device never seen, offline, no per-device printer,
        // pre-migration schema) → NULL route = today's company-wide behavior.
        $deviceRoute = $this->resolveUserPrintDevice($user, $companyId);

        // ── BILL: single job, receipt printer (unchanged behavior) ─────────
        if ($validated['type'] === 'bill') {
            if (!$deviceRoute && !$settings['receipt_printer']) {
                return response()->json(['success' => false, 'reason' => 'no_printer'], 409);
            }
            // Task 1197: query-side creator constraint — an isolated cashier
            // cannot silent-print a peer's bill receipt; peer IDs mirror
            // not_found (same no-existence-oracle stance as pra-status).
            // KOT/proof branches stay shared: kitchen slips are the shared
            // restaurant workflow (explicitly out of isolation scope).
            $exists = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('id', (int) $validated['transaction_id'])
                ->tap(fn ($q) => PosTransaction::applyCashierIsolation($q, $user))
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
            // Task 1166: the dedupe key deliberately stays TRANSACTION-scoped
            // (not per-device) — a second press for the same bill from another
            // counter within the window is still a duplicate physical copy of
            // one bill, exactly what this guard exists to prevent.
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
                'target_printer' => $deviceRoute['printer'] ?? $settings['receipt_printer'],
                'device_uid' => $deviceRoute['device_uid'] ?? null,
                'transaction_id' => (int) $validated['transaction_id'],
                'status' => 'pending',
                'created_by' => $user->id,
            ]);
            return response()->json(['success' => true, 'job_id' => $job->id]);
        }

        // ── PROOF BILL (ZFC 28 Jul 2026): pre-bill on the RECEIPT printer —
        // silent path so the desktop app never pops the Windows print dialog. ──
        if ($validated['type'] === 'proof') {
            if (!$deviceRoute && !$settings['receipt_printer']) {
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
                'target_printer' => $deviceRoute['printer'] ?? $settings['receipt_printer'],
                'device_uid' => $deviceRoute['device_uid'] ?? null,
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
            $txn = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('id', (int) $validated['transaction_id'])
                ->first();
            if (!$txn) {
                return response()->json(['success' => false, 'reason' => 'not_found'], 404);
            }
            // Task 1379 — REPRINT gate (see the order branch below). A bill
            // already stamped kot_sent_at has its slip in the kitchen.
            if (\App\Services\KotPrintService::isTransactionReprint($txn)
                && !\App\Services\PosAccessService::kotReprintAllowed($user, $company)) {
                return response()->json([
                    'success' => false,
                    'reason' => 'not_allowed',
                    'message' => __('pos.kot_reprint_not_allowed'),
                ], 403);
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
            $attrs = [
                'company_id' => $companyId,
                'type' => 'kot',
                'target_printer' => $settings['kot_printer'],
                'transaction_id' => (int) $validated['transaction_id'],
                'status' => 'pending',
                'created_by' => $user->id,
            ];
            // Task 1194: route to the counter that owns the KOT printer (ONLINE
            // only). Key added only when a stamp resolves — pre-migration prod
            // (no device_uid column) never sees it in the INSERT.
            if ($stamp = \App\Services\KotPrintService::deviceStampFor($companyId, $settings['kot_printer_device'] ?? null)) {
                $attrs['device_uid'] = $stamp;
            }
            $job = \App\Models\PosPrintJob::create($attrs);
            return response()->json(['success' => true, 'job_id' => $job->id]);
        }

        // ── KOT ─────────────────────────────────────────────────────────────
        $order = \App\Models\RestaurantOrder::where('company_id', $companyId)
            ->with('items')
            ->find((int) $validated['restaurant_order_id']);
        if (!$order) {
            return response()->json(['success' => false, 'reason' => 'not_found'], 404);
        }

        // Task 1379 — REPRINT permission gate on the SILENT path, mirroring the
        // render gate in RestaurantPosController::kitchenTicket. Placed before
        // the batch=last branch so "Akhri Add-on", plain reprints and every
        // future KOT branch below are all covered by ONE check. Only reprints
        // are gated — first fires and deltas always reach the kitchen.
        if (\App\Services\KotPrintService::isReprintRender($order, $request->boolean('delta'), $request->input('batch') === 'last')
            && !\App\Services\PosAccessService::kotReprintAllowed($user, $company)) {
            return response()->json([
                'success' => false,
                'reason' => 'not_allowed',
                'message' => __('pos.kot_reprint_not_allowed'),
            ], 403);
        }

        // Task 753 MISSED-DELTA RECOVERY: explicit "Akhri Add-on KOT" reprint —
        // ONE job on the company KOT printer with render_query batch=last (agent
        // renders the LAST printed batch + any still-unprinted rows as a clean
        // delta-style ticket). Counter-side rescue by design: no station split,
        // no counter copy. The 2-min in-flight dedupe absorbs double presses.
        if ($request->input('batch') === 'last') {
            if (!$settings['kot_printer']) {
                return response()->json(['success' => false, 'reason' => 'no_printer'], 409);
            }
            $inFlight = \App\Models\PosPrintJob::where('company_id', $companyId)
                ->where('type', 'kot')
                ->where('restaurant_order_id', $order->id)
                ->where('target_printer', $settings['kot_printer'])
                ->where('render_query', 'batch=last')
                ->whereIn('status', ['pending', 'printing'])
                ->where('created_at', '>=', now()->subMinutes(2))
                ->orderByDesc('id')->first();
            if ($inFlight) {
                return response()->json(['success' => true, 'job_id' => $inFlight->id, 'deduped' => true]);
            }
            $attrs = [
                'company_id' => $companyId,
                'type' => 'kot',
                'target_printer' => $settings['kot_printer'],
                'restaurant_order_id' => $order->id,
                'render_query' => 'batch=last',
                'status' => 'pending',
                'created_by' => $user->id,
            ];
            // Task 1194: owning-counter stamp (online only; see helper docs).
            if ($stamp = \App\Services\KotPrintService::deviceStampFor($companyId, $settings['kot_printer_device'] ?? null)) {
                $attrs['device_uid'] = $stamp;
            }
            $job = \App\Models\PosPrintJob::create($attrs);
            return response()->json(['success' => true, 'job_id' => $job->id]);
        }

        $delta = $request->boolean('delta');
        $deltaQ = $delta ? '&delta=1' : '';
        // Delta snapshot (Pizza Master edit-path bug, Aug 2026): compute the
        // unprinted rows ONCE and bake their ids into EVERY job of this send
        // (printed_item_ids). The agent prints jobs sequentially and stamps
        // kot_printed_at at result time — without the snapshot, the kitchen
        // ticket's success emptied the counter-copy delta (rendered later,
        // whereNull found nothing → 204 → no slip at the counter). Baked ids
        // keep all copies of one kitchen-send identical.
        $deltaIds = $delta
            ? $order->items->whereNull('kot_printed_at')->pluck('id')->map(fn ($i) => (int) $i)->values()->all()
            : null;
        $stations = \App\Models\PosStation::activeFor($companyId);
        // Counter KOT Copy (owner request 30 Jul 2026): DINE-IN orders only —
        // one FULL (non-station-split) copy of the KOT on the counter printer,
        // in ADDITION to the normal kitchen job(s). Best-effort: never blocks
        // or fails the main kitchen print.
        $counterCopy = function () use ($settings, $order, $companyId, $user, $delta, $deltaIds) {
            try {
                if (!($settings['counter_kot_enabled'] ?? false)) return;
                $printer = $settings['counter_kot_printer'] ?? null;
                if (!$printer || ($order->order_type ?? null) !== 'dine_in') return;
                // In-flight dedupe — client retry must not double the counter copy.
                // Delta dedupe-hit MERGES fresh ids into the still-PENDING job
                // (same rule as $makeJob) so a rapid second edit isn't dropped.
                $dupe = \App\Models\PosPrintJob::where('company_id', $companyId)
                    ->where('type', 'kot')
                    ->where('restaurant_order_id', $order->id)
                    ->where('target_printer', $printer)
                    ->where(fn($q) => $delta ? $q->where('render_query', 'delta=1') : $q->whereNull('render_query'))
                    ->whereIn('status', ['pending', 'printing'])
                    ->where('created_at', '>=', now()->subMinutes(2))
                    ->orderByDesc('id')->first();
                if ($dupe) {
                    if ($delta && $deltaIds && $dupe->status === 'pending') {
                        $merged = collect($dupe->printed_item_ids ?? [])->map(fn ($i) => (int) $i)
                            ->merge($deltaIds)->unique()->values()->all();
                        if ($merged !== ($dupe->printed_item_ids ?? [])) {
                            $dupe->update(['printed_item_ids' => $merged]);
                        }
                    }
                    return;
                }
                $attrs = [
                    'company_id' => $companyId,
                    'type' => 'kot',
                    'target_printer' => $printer,
                    'restaurant_order_id' => $order->id,
                    'render_query' => $delta ? 'delta=1' : null,
                    'printed_item_ids' => $delta ? $deltaIds : null,
                    'status' => 'pending',
                    'created_by' => $user->id,
                ];
                // Task 1194: counter copy routes to ITS owning counter (online only).
                if ($stamp = \App\Services\KotPrintService::deviceStampFor($companyId, $settings['counter_kot_printer_device'] ?? null)) {
                    $attrs['device_uid'] = $stamp;
                }
                \App\Models\PosPrintJob::create($attrs);
            } catch (\Throwable $e) { /* copy is optional — kitchen print already queued */ }
        };
        $makeJob = function (?string $printer, ?string $renderQuery, ?string $ownerDeviceUid = null) use ($companyId, $order, $user, $delta, $deltaIds) {
            // In-flight dedupe (client retry + KDS/cashier race, 30 Jul 2026):
            // an identical queued/printing job < 2 min old = same physical ticket
            // already on its way. Delta jobs now carry a BAKED id snapshot, so a
            // deduped second fire MERGES any newly-unprinted ids into the still-
            // PENDING job (render reads the row fresh at claim time); an already-
            // printing job keeps its rendered set — result-time stamping must
            // only cover rows that physically printed.
            $inFlight = \App\Models\PosPrintJob::where('company_id', $companyId)
                ->where('type', 'kot')
                ->where('restaurant_order_id', $order->id)
                ->where('target_printer', $printer)
                ->where(fn($q) => $renderQuery === null ? $q->whereNull('render_query') : $q->where('render_query', $renderQuery))
                ->whereIn('status', ['pending', 'printing'])
                ->where('created_at', '>=', now()->subMinutes(2))
                ->orderByDesc('id')->first();
            if ($inFlight) {
                if ($delta && $deltaIds && $inFlight->status === 'pending') {
                    $merged = collect($inFlight->printed_item_ids ?? [])->map(fn ($i) => (int) $i)
                        ->merge($deltaIds)->unique()->values()->all();
                    if ($merged !== ($inFlight->printed_item_ids ?? [])) {
                        $inFlight->update(['printed_item_ids' => $merged]);
                    }
                }
                return $inFlight;
            }
            $attrs = [
                'company_id' => $companyId,
                'type' => 'kot',
                'target_printer' => $printer,
                'restaurant_order_id' => $order->id,
                'render_query' => $renderQuery,
                'printed_item_ids' => ($delta && $deltaIds) ? $deltaIds : null,
                'status' => 'pending',
                'created_by' => $user->id,
            ];
            // Task 1194: owning-counter stamp — only that counter's agent claims
            // the job. Online-device rule + column guard live in the helper.
            if ($stamp = \App\Services\KotPrintService::deviceStampFor($companyId, $ownerDeviceUid)) {
                $attrs['device_uid'] = $stamp;
            }
            return \App\Models\PosPrintJob::create($attrs);
        };

        // Delta with nothing unprinted = nothing to print anywhere — succeed with
        // no jobs (mirrors the station-split empty case; the agent would only
        // 204 the job anyway, and the counter copy must not fire either).
        if ($delta && empty($deltaIds)) {
            return response()->json(['success' => true, 'job_ids' => []]);
        }

        // Zero stations => single full/delta KOT on the company KOT printer
        // (byte-identical to pre-station behavior).
        if ($stations->isEmpty()) {
            if (!$settings['kot_printer']) {
                return response()->json(['success' => false, 'reason' => 'no_printer'], 409);
            }
            $job = $makeJob($settings['kot_printer'], $delta ? 'delta=1' : null, $settings['kot_printer_device'] ?? null);
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
            $job = $makeJob($printer, 'station=' . $sid . $deltaQ, \App\Services\KotPrintService::stationDeviceUid($station, $settings));
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
            $plan[] = [$printer, 'station=' . $sid . $deltaQ, \App\Services\KotPrintService::stationDeviceUid($station, $settings)];
        }
        $jobIds = [];
        foreach ($plan as [$printer, $rq, $ownerUid]) {
            $jobIds[] = $makeJob($printer, $rq, $ownerUid)->id;
        }
        $counterCopy();
        return response()->json(['success' => true, 'job_ids' => $jobIds]);
    }

    /**
     * Test print — "kaun sa printer asli hai".
     *
     * A Windows box that has had the same thermal printer installed a few
     * times keeps a queue per install: "XP-80C", "XP-80C (copy 2)", "POS-80".
     * Only one is still bound to the live port; the others accept jobs and
     * drop them. Windows calls that submission a success, so the agent reports
     * success and every bill looks printed on the server while the counter
     * sees no paper at all — the shop can only report "print nahi nikalta".
     *
     * This enqueues a tiny slip that carries the QUEUE'S OWN NAME, so whichever
     * paper physically comes out names the printer to select. Deliberately
     * independent of the silent-print master switch: the point is to find a
     * working printer BEFORE printing is turned on. Admin/manager only, and
     * rate-limited on the route — every press costs the shop paper.
     */
    public function apiTestPrintJob(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) { abort(403); }

        // pos/* is CSRF-exempt (agent + sendBeacon paths live under it), so this
        // endpoint verifies the token itself. Without it a hostile page could
        // make a logged-in owner's own browser burn the shop's paper roll.
        $token = (string) ($request->header('X-CSRF-TOKEN') ?: $request->input('_token'));
        if (!$request->hasSession() || $token === ''
            || !hash_equals((string) $request->session()->token(), $token)) {
            abort(419);
        }

        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }

        $validated = $request->validate([
            'printer' => 'required|string|max:255',
            'device_uid' => 'nullable|string|max:64',
        ]);
        $printer = trim((string) $validated['printer']);
        $deviceUid = trim((string) ($validated['device_uid'] ?? ''));
        $deviceAware = \App\Http\Controllers\AgentController::deviceRoutingReady();
        $device = null;

        if ($deviceUid !== '' && $deviceAware) {
            $device = \App\Models\PosAgentDevice::where('company_id', $companyId)
                ->where('device_uid', $deviceUid)
                ->first();
            if (!$device) {
                return response()->json(['success' => false, 'reason' => 'unknown_device'], 422);
            }
            // A counter can only be asked to test a printer IT reported.
            $own = collect($device->printers ?? [])->pluck('name')->all();
            if (!in_array($printer, $own, true)) {
                return response()->json(['success' => false, 'reason' => 'unknown_printer'], 422);
            }
            if (!$device->isOnline()) {
                return response()->json(['success' => false, 'reason' => 'device_offline'], 409);
            }
        } else {
            $deviceUid = '';
            $known = collect($company->printerSettings()['available_printers'] ?? [])->pluck('name')->all();
            if (!in_array($printer, $known, true)) {
                return response()->json(['success' => false, 'reason' => 'unknown_printer'], 422);
            }
            if (!$company->agentOnline()) {
                return response()->json(['success' => false, 'reason' => 'agent_offline'], 409);
            }
        }

        $payload = [
            'company_id' => $companyId,
            'type' => 'test',
            'target_printer' => $printer,
            'status' => 'pending',
            'created_by' => $user->id,
        ];
        if ($deviceUid !== '' && $deviceAware) {
            $payload['device_uid'] = $deviceUid;
        }
        $job = \App\Models\PosPrintJob::create($payload);

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'printer' => $printer,
        ]);
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
        // Local Billing card (Task 1358): archived local bills silently reserve
        // their L-numbers, so the card explains WHY the series is stuck and offers
        // the clear action. count 0 = nothing to show.
        $localSeries = $this->localSeriesStatus((int) $companyId);
        // Deleted local bills can leave a customer-spend line behind (owner asked
        // 25 Aug 2026 "phir delete ka faida kya"): show how many exist so the shop
        // can wipe the leftovers, not just stop new ones.
        $localSeries['spend_records'] = $this->customerSpendRecordCount((int) $companyId);
        return view('pos.customize', compact('company', 'localSeries'));
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
        // Billing Scope (07 Aug 2026): for LOCAL-scoped staff the dashboard flips
        // to the LOCAL stream instead (their whole world is offline billing —
        // showing them PRA figures would leak the other stream).
        $dashScope = auth('pos')->user()?->posBillingScope() ?? 'both';
        if ($dashScope === 'local') {
            $excludeLocal = function ($q) {
                $q->where('invoice_mode', 'local')->orWhere(function ($s) {
                    $s->whereNull('pra_status')->whereNull('pra_invoice_number');
                });
            };
            $excludeLocalRaw = function ($q) {
                $q->where('t.invoice_mode', 'local')->orWhere(function ($s) {
                    $s->whereNull('t.pra_status')->whereNull('t.pra_invoice_number');
                });
            };
        } else {
            $excludeLocal = function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            };
            $excludeLocalRaw = function ($q) {
                $q->where('t.invoice_mode', 'pra')->orWhereNull('t.invoice_mode');
            };
        }

        // Task 1197 — per-cashier day figures: an ISOLATED cashier's dashboard
        // KPIs count ONLY their own bills (company switch "Cashier sirf apni
        // sale dekhe", default ON); an admin/manager may inspect any single
        // cashier via ?cashier=ID (the per-cashier selector). One variable
        // drives every KPI query below — ANDs with the scope filters (compose,
        // never replace). Drafts / notifications / opening cash stay shared
        // (holds are a handover workflow, not sales).
        $dashUser = auth('pos')->user();
        $dashCashierId = null;
        if ($dashUser?->posSalesIsolated()) {
            $dashCashierId = (int) $dashUser->id;
        } elseif (($dashUser?->isPosAdmin() ?? false) && $request->filled('cashier') && $request->get('cashier') !== 'all') {
            $dashCashierId = (int) $request->get('cashier');
        }
        $dashOnly = function ($q) use ($dashCashierId) {
            if ($dashCashierId) {
                $q->where('created_by', $dashCashierId);
            }
            return $q;
        };
        $dashOnlyRaw = function ($q) use ($dashCashierId) {
            if ($dashCashierId) {
                $q->where('t.created_by', $dashCashierId);
            }
            return $q;
        };

        // Multi-branch v1 (Task 1347): every dashboard figure follows the active
        // branch. Passed as a nested where() closure — applyToQuery adds nothing
        // when the company has no branches (or the owner picked "all branches"),
        // and an empty nested group is dropped by the query builder, so the
        // single-branch SQL is byte-identical to before. Legacy rows
        // (branch_id NULL) stay visible by design.
        $dashBranchSvc = app(\App\Services\BranchContextService::class);
        $dashBranch = function ($q) use ($dashBranchSvc) { $dashBranchSvc->applyToQuery($q, 'branch_id'); };
        $dashBranchRaw = function ($q) use ($dashBranchSvc) { $dashBranchSvc->applyToQuery($q, 't.branch_id'); };

        // Return / credit-note netting (Task 578, same convention as day-close &
        // reports, Task 570): revenue figures are SIGNED (returns subtract),
        // bill counts stay SALES-only. Schema-guarded for prod drift —
        // pre-migration boxes fall back to the old unsigned sums.
        $dashTypeReady = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type');
        $dashSignExpr = $dashTypeReady ? "CASE WHEN transaction_type = 'return' THEN -1 ELSE 1 END" : '1';
        $dashSaleRowExpr = $dashTypeReady ? "CASE WHEN transaction_type = 'return' THEN 0 ELSE 1 END" : '1';
        // Item-level joins (profit / top products / coverage) EXCLUDE return
        // transactions entirely — same as range analytics (never netted there).
        $dashExcludeReturnsRaw = function ($q) use ($dashTypeReady) {
            if ($dashTypeReady) {
                $q->where(function ($w) {
                    $w->whereNull('t.transaction_type')->orWhere('t.transaction_type', '!=', 'return');
                });
            }
        };

        $todayStats = PosTransaction::where('company_id', $companyId)->where($dashBranch)
            ->where('status', 'completed')
            ->where('business_date', $bizToday)
            ->where($excludeLocal)
            ->tap($dashOnly)
            ->selectRaw("COALESCE(SUM({$dashSaleRowExpr}),0) as count, COALESCE(SUM(({$dashSignExpr}) * total_amount),0) as revenue")
            ->first();
        // (Task 988: the avg_ticket figure is gone — the Avg. Order card was
        // replaced by New Customers on every dashboard style, and no other
        // view reads it.)

        // Task 109 (ZFC, 2 Aug 2026): Pending Bills — provisional bills of the
        // current BUSINESS day that are still not FINAL. Triple-filter per
        // pos-provisional rules (completed + invoice_mode='local' +
        // pra_status='local'); hide_archived global scope excludes archived.
        // Task 705: manager default PRA-only — Pending (local) tile zeroed until
        // the khufia local-check mode is ON.
        $pendingProvisional = (auth('pos')->user()?->posHidesLocalStream() ?? false) ? 0
            : PosTransaction::where('company_id', $companyId)->where($dashBranch)
            ->where('status', 'completed')
            ->where('invoice_mode', 'local')
            ->where('pra_status', 'local')
            ->where('business_date', $bizToday)
            ->tap($dashOnly)
            ->count();

        $monthStats = PosTransaction::where('company_id', $companyId)->where($dashBranch)
            ->where('status', 'completed')
            ->where('business_date', '>=', now()->startOfMonth()->toDateString())
            ->where($excludeLocal)
            ->tap($dashOnly)
            ->selectRaw("COALESCE(SUM({$dashSaleRowExpr}),0) as count, COALESCE(SUM(({$dashSignExpr}) * total_amount),0) as revenue")
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

        // Cost / profit aggregation — PROFIT-FREEZE (Task 423, Aug 2026):
        // use the per-line frozen cost_price snapshot from pos_transaction_items
        // so a purchase-rate edit never retroactively rewrites dashboard profit.
        // Mirrors the range-analytics freeze and the FBR POS dashboard fix.
        // Lines without a stored snapshot (NULL/zero) are cost-unknown → excluded
        // from cost; coverage_pct shows the admin how complete the data is.
        // hasColumn guard: the column may not yet exist on older PROD schemas.
        $hasDashFrozenCost = \Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'cost_price');
        if ($hasDashFrozenCost) {
            $profitRow = \DB::table('pos_transactions as t')->where($dashBranchRaw)
                ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
                ->where('t.company_id', $companyId)
                ->where('t.status', 'completed')
                ->where('t.business_date', '>=', $periodStart)
                ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
                ->where($excludeLocalRaw)
                ->where($dashExcludeReturnsRaw)
                ->tap($dashOnlyRaw)
                ->selectRaw('
                    COALESCE(SUM(CASE WHEN COALESCE(i.cost_price,0) > 0 THEN i.subtotal ELSE 0 END), 0) as gross_revenue,
                    COALESCE(SUM(CASE WHEN COALESCE(i.cost_price,0) > 0 THEN i.cost_price * i.quantity ELSE 0 END), 0) as total_cost
                ')->first();
        } else {
            // Legacy fallback (pre-migration): join live product cost.
            $profitRow = \DB::table('pos_transactions as t')->where($dashBranchRaw)
                ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
                ->leftJoin('pos_products as p', function ($j) {
                    $j->on('p.id', '=', 'i.item_id')->where('i.item_type', '=', 'product');
                })
                ->where('t.company_id', $companyId)
                ->where('t.status', 'completed')
                ->where('t.business_date', '>=', $periodStart)
                ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
                ->where($excludeLocalRaw)
                ->where($dashExcludeReturnsRaw)
                ->tap($dashOnlyRaw)
                ->selectRaw('
                    COALESCE(SUM(i.subtotal), 0) as gross_revenue,
                    COALESCE(SUM(COALESCE(p.cost_price, 0) * i.quantity), 0) as total_cost
                ')->first();
        }

        $periodOrders = PosTransaction::where('company_id', $companyId)->where($dashBranch)
            ->where('status', 'completed')
            ->where('business_date', '>=', $periodStart)
            ->where($excludeLocal)
            ->tap($dashOnly)
            ->selectRaw("COALESCE(SUM({$dashSaleRowExpr}),0) as count, COALESCE(SUM(({$dashSignExpr}) * total_amount),0) as revenue")
            ->first();

        // PROFIT-FREEZE alignment: use costed-lines revenue (gross_revenue) as the
        // denominator for profit/margin — same as range analytics. Counting all-lines
        // revenue against only costed-lines cost would treat unknown-cost lines as
        // 100% profit and overstate both profit and margin on post-migration shops
        // that still have old bills without a snapshot. When the frozen-cost column
        // is absent (legacy fallback branch), gross_revenue already equals all-lines
        // revenue so the behaviour is identical to before.
        $totalCost         = (float) ($profitRow->total_cost ?? 0);
        $costedRevenue     = (float) ($profitRow->gross_revenue ?? 0); // only lines with a cost snapshot
        $totalRevenue      = (float) ($periodOrders->revenue ?? 0);    // all-lines (for the orders KPI)
        $totalProfit       = max(0, $costedRevenue - $totalCost);       // floor at 0 for display
        $marginPct         = $costedRevenue > 0 ? round(($costedRevenue - $totalCost) / $costedRevenue * 100, 1) : 0;

        $profitStats = [
            'period'   => $period,
            'orders'   => (int) ($periodOrders->count ?? 0),
            'revenue'  => $costedRevenue,  // revenue of costed lines — consistent with cost/profit
            'cost'     => $totalCost,
            'profit'   => $totalProfit,
            'margin'   => $marginPct,
            'all_revenue' => $totalRevenue, // kept for caller completeness (unused in view today)
        ];

        // Top products by quantity sold (period)
        $topSold = \DB::table('pos_transactions as t')->where($dashBranchRaw)
            ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->where('t.business_date', '>=', $periodStart)
            ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
            ->where($excludeLocalRaw)
            ->where($dashExcludeReturnsRaw)
            ->tap($dashOnlyRaw)
            ->where('i.item_type', 'product')
            ->selectRaw('i.item_id, MAX(i.item_name) as name, SUM(i.quantity) as qty, SUM(i.subtotal) as revenue')
            ->groupBy('i.item_id')
            ->orderByDesc('qty')
            ->limit(5)
            ->get();

        // Top products by profit (period) — PROFIT-FREEZE: use frozen item snapshot.
        if ($hasDashFrozenCost) {
            $topProfit = \DB::table('pos_transactions as t')->where($dashBranchRaw)
                ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
                ->where('t.company_id', $companyId)
                ->where('t.status', 'completed')
                ->where('t.business_date', '>=', $periodStart)
                ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
                ->where($excludeLocalRaw)
                ->where($dashExcludeReturnsRaw)
                ->tap($dashOnlyRaw)
                ->where('i.item_type', 'product')
                ->where('i.cost_price', '>', 0)   // only lines with a frozen snapshot
                ->selectRaw('
                    i.item_id, MAX(i.item_name) as name,
                    SUM(i.subtotal) as revenue,
                    SUM(i.cost_price * i.quantity) as cost,
                    SUM(i.subtotal - i.cost_price * i.quantity) as profit
                ')
                ->groupBy('i.item_id')
                ->orderByDesc('profit')
                ->limit(5)
                ->get();
        } else {
            // Legacy fallback (pre-migration): join live product cost.
            $topProfit = \DB::table('pos_transactions as t')->where($dashBranchRaw)
                ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
                ->join('pos_products as p', 'p.id', '=', 'i.item_id')
                ->where('t.company_id', $companyId)
                ->where('t.status', 'completed')
                ->where('t.business_date', '>=', $periodStart)
                ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
                ->where($excludeLocalRaw)
                ->where($dashExcludeReturnsRaw)
                ->tap($dashOnlyRaw)
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
        }

        // Low margin alert: always reads live product cost (forward-looking config signal,
        // intentionally NOT frozen — a shopkeeper correcting a rate should see the update here).
        $lowMargin = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('cost_price', '>', 0)
            ->whereRaw('price > 0')
            ->whereRaw('((price - cost_price) / price) < 0.15')
            ->orderByRaw('((price - cost_price) / NULLIF(price,0)) asc')
            ->limit(5)
            ->get(['id', 'name', 'price', 'cost_price']);

        // Coverage: when using frozen snapshots, show % of sold product lines in the
        // period that carry a cost snapshot. Pre-migration fallback: active-product count.
        if ($hasDashFrozenCost) {
            $covRow = \DB::table('pos_transactions as t')->where($dashBranchRaw)
                ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
                ->where('t.company_id', $companyId)
                ->where('t.status', 'completed')
                ->where('t.business_date', '>=', $periodStart)
                ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
                ->where($excludeLocalRaw)
                ->where($dashExcludeReturnsRaw)
                ->tap($dashOnlyRaw)
                ->where('i.item_type', 'product')
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN COALESCE(i.cost_price,0) > 0 THEN 1 ELSE 0 END) as with_cost')
                ->first();
            $costCoverage = [
                'with_cost' => (int) ($covRow->with_cost ?? 0),
                'total'     => (int) ($covRow->total ?? 0),
            ];
        } else {
            $costCoverage = [
                'with_cost' => PosProduct::where('company_id', $companyId)->where('is_active', true)->where('cost_price', '>', 0)->count(),
                'total'     => PosProduct::where('company_id', $companyId)->where('is_active', true)->count(),
            ];
        }
        // ─────────────────────────────────────────────────────────────────────

        $recentTransactions = PosTransaction::where('company_id', $companyId)->where($dashBranch)
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->tap($dashOnly)
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $paymentBreakdown = PosTransaction::where('company_id', $companyId)->where($dashBranch)
            ->where('status', 'completed')
            ->where('business_date', $bizToday)
            ->where($excludeLocal)
            ->tap($dashOnly)
            ->selectRaw("payment_method, COALESCE(SUM({$dashSaleRowExpr}),0) as count, COALESCE(SUM(({$dashSignExpr}) * total_amount),0) as total")
            ->groupBy('payment_method')
            ->get();

        // Per-cashier toggle (owner rule Jul 2026): dashboard pill shows THIS user's
        // effective reporting state, not the company-wide flag.
        $praStatus = (bool) auth('pos')->user()?->praReportingEnabled($company);

        $drafts = PosTransaction::where('company_id', $companyId)->where($dashBranch)
            ->where('status', 'draft')
            ->with('items')
            ->orderBy('updated_at', 'desc')
            ->take(20)
            ->get();

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';

        // ── Task 666: "Aaj ka Khaata" — stream-wise today sale/tax summary ──
        // Build extracted to PosTodayKhata (14 Aug 2026) so the RESTAURANT
        // dashboard shows the identical card; conventions documented there.
        // Task 1347: $dashBranch keeps the card on the active branch — without it
        // the Khaata figures stay company-wide while every tile around them
        // follows the switcher.
        $todayKhata = \App\Services\PosTodayKhata::build($companyId, $bizToday, $user, $dashCashierId, $dashBranch);
        // ─────────────────────────────────────────────────────────────────────

        // Task 988 (owner video, 16 Aug 2026): the Today's Revenue card shows the
        // TOTAL sale — PRA + Local (+ exempt) combined, i.e. exactly the sum of
        // the ledger figures this user is allowed to see — so the owner never
        // has to add PRA + Local himself. Scope-aware (no local-stream leak:
        // PRA-scoped / hidden-local users stay PRA-only, local-scoped stay
        // local-only). The chart / profit / payment tiles keep their existing
        // single-stream sources ($todayStats et al.).
        $todayTotalSale = \App\Services\PosTodayKhata::combinedSale($companyId, $user, $bizToday, $bizToday, $dashCashierId, $dashBranch);
        // Monthly card gets the same combined treatment so the two revenue
        // cards can never contradict each other.
        $monthTotalSale = \App\Services\PosTodayKhata::combinedSale($companyId, $user, now()->startOfMonth()->toDateString(), null, $dashCashierId, $dashBranch);

        // Task 988: "New Customers" card (replaces Avg. Order — owner voice note):
        // customers added in the current BUSINESS day + this calendar month.
        // pos_customers has no business_date column — window = biz day start
        // (bizToday @ per-company cutoff) in app TZ.
        $newCustWindowStart = \Carbon\Carbon::parse(
            $bizToday . ' ' . \App\Services\PosBusinessDay::cutoffFor($companyId),
            config('app.timezone')
        );
        // hasTable drift guard (PROD schema-drift policy): a schema without
        // pos_customers must still render the dashboard — counts show 0.
        // Task 1197: with a staff filter active ($dashCashierId — isolated
        // cashier OR admin's per-cashier view) the card counts only new
        // customers that appear on THAT cashier's bills (pos_customers has no
        // created_by column, so bill linkage is the attributable metric —
        // never the company-wide total, which would leak/mislead).
        $hasCustomersTable = \Illuminate\Support\Facades\Schema::hasTable('pos_customers');
        $newCustQ = function ($since) use ($companyId, $dashCashierId) {
            $q = PosCustomer::where('company_id', $companyId)
                ->where('created_at', '>=', $since);
            if ($dashCashierId) {
                if (!\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'customer_id')) {
                    return 0; // drift guard: fail closed, never company-wide
                }
                $q->whereIn('id', PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->where('created_by', $dashCashierId)
                    ->whereNotNull('customer_id')
                    ->select('customer_id'));
            }
            return $q->count();
        };
        $newCustomersToday = $hasCustomersTable ? $newCustQ($newCustWindowStart) : 0;
        $newCustomersMonth = $hasCustomersTable ? $newCustQ(now()->startOfMonth()) : 0;

        $allowedStyles = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify', 'saaf'];
        $dashboardStyle = in_array($company->pos_dashboard_style, $allowedStyles) ? $company->pos_dashboard_style : 'default';
        // The PRA dashboard is also used by restaurant-shaped companies. Use
        // the canonical plan/override gate (not restaurant_mode) so its pending
        // tile can warn about the same held orders that block day close.
        $isRestaurant = \App\Services\PosFeatureService::restaurantAllowed($company);
        [$openOrdersCount, $counterOrdersCount, $heldNoTableCount] = $this->pendingRestaurantOrderCounts(
            $companyId,
            $isRestaurant
        );
        $isAdmin = !$isCashier;

        // Saaf style extras (lazy — only queried when the clean dashboard is active):
        // yesterday's revenue for the vs-kal delta + today's PRA-synced bill count.
        $yesterdayRevenue = null;
        $praSyncedToday = null;
        if ($dashboardStyle === 'saaf') {
            // Task 988: vs-kal delta must compare like-with-like — yesterday's
            // figure is the same scope-aware COMBINED sale as the today card.
            $saafYesterdayBiz = \Carbon\Carbon::parse($bizToday)->subDay()->toDateString();
            $yesterdayRevenue = \App\Services\PosTodayKhata::combinedSale($companyId, $user, $saafYesterdayBiz, $saafYesterdayBiz, $dashCashierId, $dashBranch);
            // Synced-bill count stays SALES-only (a submitted credit note is
            // not a bill; counting it would disagree with the today tile).
            $praSyncedToday = (int) PosTransaction::where('company_id', $companyId)->where($dashBranch)
                ->where('status', 'completed')
                ->where('business_date', $bizToday)
                ->where($excludeLocal)
                ->where('pra_status', 'submitted')
                ->tap($dashOnly)
                ->selectRaw("COALESCE(SUM({$dashSaleRowExpr}),0) as c")
                ->value('c');
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
        // Task 1360: day close is per branch — the card must show THIS branch's
        // drawer and only call the day closed once this branch has closed it.
        // Task 1375: with counters the card records one float PER counter, so
        // it shows the drawers and their sum; $dayOpening stays the shop-drawer
        // row (a counter-less shop's only row, exactly as before).
        $dashBranchId = $this->dayCloseBranchId();
        $todayDate = $bizToday;
        $dayOpening = \App\Models\PosDayOpening::forDate($companyId, $todayDate, $dashBranchId);
        $openingDrawers = \App\Models\PosDayOpening::drawersForDate($companyId, $todayDate, $dashBranchId);
        $dayOpeningTotal = $openingDrawers->isEmpty() ? null : round((float) $openingDrawers->sum(), 2);
        $openingCounters = \App\Services\PosCounterDrawer::counters($companyId);
        $todayClosed = PosDayCloseReport::where('company_id', $companyId)
            ->forBranch($dashBranchId)
            ->where('report_date', $todayDate)
            ->exists();

        // Stranded-day warning (Task 466): the day-close page already shows a
        // detailed red banner (Task 455), but staff only see it if they open
        // that page. Compact echo on the dashboard — everyone lands here.
        // dayCloseAllowed decides whether the link is actionable (cashiers
        // without day-close rights get info-only text, not a dead-end link).
        $unclosedPriorDays = $this->unclosedPriorBusinessDays($companyId, null, false, $dashBranchId);
        $canDayClose = \App\Services\PosAccessService::dayCloseAllowed(auth('pos')->user(), $company);

        // Task 1161: "Purane customer khamosh hain" — repeat customers whose
        // last order is older than the inactivity window (cached per company,
        // no cron). Admin/manager-only card, same visibility as pending bills.
        $inactiveRegulars = $isAdmin ? \App\Services\PosRepeatCustomerAlert::listFor($companyId) : collect();

        // Task 1197: per-cashier selector data for the day figures
        // (admin/manager only — an isolated cashier is forced onto self and
        // never gets the dropdown).
        $dashTeamMembers = $isAdmin
            ? User::where('company_id', $companyId)
                ->whereNotNull('pos_role')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'pos_role'])
            : collect();

        // Owner (25 Aug 2026): rider ki pari hui settlement bhi dashboard par
        // dikhni chahiye — "baqi tamam issues dashboard par aa jate hain".
        //
        $riderPending = $this->pendingRiderKhata($companyId, $company);
        // Owner (25 Aug 2026): banner sirf tab aata hai jab kuch para ho, magar
        // chip ki jagah pakki honi chahiye — is liye poora summary bhi jata hai.
        $riderChip = \App\Services\PosRiderKhataAlert::summary((int) $companyId, $company);

        return view('pos.dashboard', compact(
            'company', 'todayStats', 'monthStats', 'recentTransactions', 'paymentBreakdown', 'praStatus', 'drafts', 'isCashier',
            'dashboardStyle', 'isRestaurant', 'isAdmin', 'notifications',
            'profitStats', 'topSold', 'topProfit', 'lowMargin', 'costCoverage',
            'dayOpening', 'dayOpeningTotal', 'openingDrawers', 'openingCounters',
            'todayClosed', 'yesterdayRevenue', 'praSyncedToday',
            'pendingProvisional', 'openOrdersCount', 'counterOrdersCount', 'heldNoTableCount',
            'unclosedPriorDays', 'canDayClose', 'todayKhata',
            'todayTotalSale', 'monthTotalSale', 'newCustomersToday', 'newCustomersMonth',
            'inactiveRegulars', 'dashTeamMembers', 'dashCashierId', 'riderPending', 'riderChip'
        ));
    }

    /**
     * Rider settlement pending — dashboard alert (owner, 25 Aug 2026).
     *
     * ZFC ka wakia: day-close ke waqt ek bill isliye reh gaya ke rider ka cash
     * abhi wasool nahi hua tha — khata guard aisa bill archive karta hai, delete
     * nahi, aur usay baad mein koi dobara nahi samet‌ta. Shop ko pata hi tab
     * chala jab bill agle din tak para raha. Owner ki farmaish: "jis tarah baqi
     * issues dashboard par aate hain, is ka bhi alert ho — kis rider ki
     * settlement pari hai, click karo to seedha settlement par chala jaye."
     *
     * Predicate PosRider::openCashBills() ka hu-ba-hu aaina hai (archived bills
     * bhi shamil — warna day-close ke baad khata gayab lagta hai). Dashboard
     * company ki liability reminder hai, reporting-stream report nahi: owner
     * ya manager ko cash tab tak dikhna chahiye jab tak waqai settle na ho.
     */
    private function pendingRiderKhata($companyId, $company)
    {
        // Hisaab PosRiderKhataAlert mein rehta hai: ek hi shop do alag dashboard
        // par utar sakti hai (retail /pos/dashboard aur restaurant
        // /pos/restaurant/dashboard) aur dono ko wahi khata dikhna chahiye.
        return \App\Services\PosRiderKhataAlert::pending((int) $companyId, $company);
    }

    /**
     * Held restaurant orders shown on the shared dashboard pending-bills tile.
     * Open orders deliberately have no business-day restriction: yesterday's
     * forgotten hold still blocks today's close and must remain actionable.
     */
    private function pendingRestaurantOrderCounts(int $companyId, bool $isRestaurant): array
    {
        if (!$isRestaurant || !\Illuminate\Support\Facades\Schema::hasTable('restaurant_orders')) {
            return [0, 0, 0];
        }

        $open = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->count();

        $counter = 0;
        // 25 Aug 2026: teesri ginti — CASHIER ke park kiye hue BINA-TABLE order
        // (takeaway/delivery hold). Yeh na Tables page par dikhte hain (table hi
        // nahi) aur na ghanti panel mein (woh sirf waiter-source hai) — inhein
        // "open tables" mein ginna dashboard se DEAD-END link banata tha. Ab
        // apna alag chip: sale screen ka Held window (?open_held=1).
        $heldNoTable = 0;
        if (\Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'source')
            && \Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'table_id')) {
            $counter = RestaurantOrder::where('company_id', $companyId)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->where('source', 'waiter')
                ->whereNull('table_id')
                ->count();

            $heldNoTable = RestaurantOrder::where('company_id', $companyId)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->where(function ($q) {
                    $q->where('source', '!=', 'waiter')->orWhereNull('source');
                })
                ->whereNull('table_id')
                ->count();
        }

        return [$open, $counter, $heldNoTable];
    }

    /**
     * "Pichla din band nahi hua" popup state (owner request, 23 Aug 2026).
     *
     * The day-close page and the dashboard already carry a red banner, but the
     * shop opens the app and goes straight to New Sale — so an unclosed day was
     * only discovered later, with a fresh day's bills already on top of it.
     * The sale screen and dashboard now ASK for this on load and pop a modal
     * that never times out; the shop dismisses it, or closes the day.
     *
     * Deliberately not cached: the dashboard runs the very same query on every
     * load, and a cached "still open" would keep nagging after a close.
     */
    public function dayClosePendingState()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $silent = ['pending' => false, 'can_close' => false];

        // Only the people who may actually close a day get nagged; a cashier
        // without the right would just be stuck staring at it every morning.
        if (!\App\Services\PosAccessService::dayCloseAllowed(auth('pos')->user(), $company)) {
            return response()->json($silent);
        }
        // "All branches" is a reporting view — no day can be closed from there.
        if ($this->dayCloseAllBranchesView()) {
            return response()->json($silent);
        }

        $days = $this->unclosedPriorBusinessDays($companyId, null, false, $this->dayCloseBranchId());
        if ($days->isEmpty()) {
            return response()->json(['pending' => false, 'can_close' => true]);
        }

        return response()->json([
            'pending' => true,
            'can_close' => true,
            'count' => $days->count(),
            'labels' => $days->map(fn ($d) => \Carbon\Carbon::parse($d)->format('d M Y'))->values()->all(),
            // Relative: an absolute https URL breaks plain-http browsing.
            'url' => route('pos.day-close', ['date' => $days->first()], false),
        ]);
    }

    /**
     * Stranded-day detection (Task 455, shared for Task 466): prior business
     * days that have bills (archived rows included) but NO PosDayCloseReport
     * row. Keyed by business_date (created_at date on pre-migration schemas).
     * Returns an ascending collection of Y-m-d strings (max 30 days back).
     */
    private function unclosedPriorBusinessDays(int $companyId, ?string $excludeDate = null, bool $oldestFirst = false, ?int $branchId = null)
    {
        $bizToday = \App\Services\PosBusinessDay::current($companyId);
        $hasBizDate = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'business_date');
        // Closed dates FIRST, excluded inside the query (Task 516): the old
        // shape limited to the newest 30 dates BEFORE dropping closed ones, so
        // once the newest 30 were all closed, older still-open days became
        // invisible — the bulk close could never finish a 31+ day backlog.
        // Normalized in PHP (no whereIn on report_date — drivers that store
        // DATE with a time part, e.g. sqlite tests, would miss every match
        // and resurrect closed days); a company has few report rows.
        // Task 1360: a day is "closed" only for the branch that closed it —
        // Main Shop's Z-report must not hide Gulberg's still-open day.
        $closedDates = PosDayCloseReport::where('company_id', $companyId)
            ->forBranch($branchId)
            ->pluck('report_date')
            ->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString());
        $priorDates = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where(fn ($q) => $this->scopeToBranch($q, $branchId))
            ->when($hasBizDate,
                fn ($q) => $q->where('business_date', '<', $bizToday)->selectRaw('business_date as d'),
                fn ($q) => $q->whereDate('created_at', '<', $bizToday)->selectRaw('DATE(created_at) as d'))
            ->when($closedDates->isNotEmpty(), fn ($q) => $q->when($hasBizDate,
                fn ($qq) => $qq->whereNotIn('business_date', $closedDates->all()),
                fn ($qq) => $qq->whereNotIn(\DB::raw('DATE(created_at)'), $closedDates->all())))
            ->groupBy('d')
            // Banner shows the newest 30; the BULK close pages OLDEST-first
            // (Task 516): performDayClose's backlog wash sweeps local bills
            // with business_date <= close date, so closing a newer day before
            // discovering an older one would steal the older day's bills into
            // the wrong report and leave it an artificial zero-report.
            ->orderBy('d', $oldestFirst ? 'asc' : 'desc')
            ->limit(30)
            ->pluck('d')
            ->map(fn ($d) => (string) $d);

        return $priorDates
            ->reject(fn ($d) => $closedDates->contains($d) || $d === $excludeDate)
            ->sort()
            ->values();
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
            // Task 1375: which DRAWER this float belongs to. 0 / absent = the
            // shop drawer, i.e. exactly what every counter-less shop sends.
            'terminal_id' => 'nullable|integer|min:0',
        ]);
        // Opening cash is a TODAY-only entry — the UI never sends a date, and
        // accepting one would let a raw POST seed arbitrary future/past days.
        // Task 56: "today" = the company's current BUSINESS day (per-company
        // cutoff), matching the dashboard card that posts here.
        $date = \App\Services\PosBusinessDay::current($companyId);

        if (!\Illuminate\Support\Facades\Schema::hasTable('pos_day_openings')) {
            return back()->with('error', __('pos.opening_cash_feature_setup'));
        }

        // Task 1360: opening cash belongs to the drawer of the branch on screen
        // — the same scope the day close will reconcile it against. From the
        // owner's "all branches" view there is no single drawer to open.
        if ($this->dayCloseAllBranchesView()) {
            return back()->with('error', __('pos.dayclose_pick_branch'));
        }
        $openingBranchId = $this->dayCloseBranchId();

        // Once the day is closed the Z-report is immutable — opening locks too.
        $closed = PosDayCloseReport::where('company_id', $companyId)
            ->forBranch($openingBranchId)
            ->where('report_date', $date)
            ->exists();
        if ($closed) {
            return back()->with('error', __('pos.day_closed_opening_cash_locked'));
        }

        // Task 1375: per-counter drawer. A crafted id from another company (or a
        // retired counter) silently falls back to the shop drawer — same
        // convention the sale screen uses for counter attribution.
        $openingTerminalId = 0;
        $terminalName = null;
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_openings', 'terminal_id')
            && (int) $request->input('terminal_id', 0) > 0) {
            $terminal = \App\Models\PosTerminal::where('company_id', $companyId)
                ->where('is_active', true)
                ->find((int) $request->input('terminal_id'));
            if ($terminal) {
                $openingTerminalId = (int) $terminal->id;
                $terminalName = $terminal->terminal_name;
            }
        }
        // A counter's drawer locks the moment THAT counter closes — the money
        // has already been counted against this float.
        if ($openingTerminalId > 0
            && \App\Services\PosCounterDrawer::isClosed($companyId, $openingTerminalId, $date)) {
            return back()->with('error', __('pos.counter_already_closed'));
        }

        $openingKey = ['company_id' => $companyId, 'business_date' => $date];
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_openings', 'branch_id')) {
            $openingKey['branch_id'] = PosDayCloseReport::branchKey($openingBranchId);
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_openings', 'terminal_id')) {
            $openingKey['terminal_id'] = $openingTerminalId;
        }
        \App\Models\PosDayOpening::updateOrCreate(
            $openingKey,
            [
                'opening_cash' => round((float) $request->input('opening_cash'), 2),
                'entered_by' => $user?->id,
                'notes' => $request->input('notes'),
            ]
        );

        $amount = number_format((float) $request->input('opening_cash'), 2);

        return back()->with('success', $terminalName
            ? __('pos.counter_opening_saved', ['counter' => $terminalName, 'amount' => $amount])
            : __('pos.opening_cash_saved', ['amount' => $amount]));
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

    /**
     * Task 767: dismiss the one-time "KOT centering still ON — verify your
     * printout" layout banner (stamped by the notify_kot_center_residual_shops
     * migration for shops that KEPT explicit centering after the Task 761
     * accidental-center reset). Admin/manager only — the banner itself is
     * never rendered for cashiers/confined roles (isPosAdmin gate in layout),
     * so a 403 here means someone is poking the route directly.
     */
    public function dismissKotCenterNotice()
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can dismiss this notice.');
        }

        $companyId = app('currentCompanyId');
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_center_notice_at')) {
            \Illuminate\Support\Facades\DB::table('companies')
                ->where('id', $companyId)
                ->update(['kot_center_notice_at' => null]);
        }

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
        if (($features->kot || $features->tables) && class_exists(RestaurantOrder::class)) {
            // creator: staff name printed on every held-window row (owner batch,
            // 26 Aug 2026). Eager-loaded — live throws on lazy relation access.
            $heldOrders = RestaurantOrder::where('company_id', $companyId)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->with(['table', 'items', 'creator:id,name'])->orderBy('created_at', 'desc')->get();
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

        // Task 502 (11 Aug 2026): Tables page ke open-order cards ?recall_order={id}
        // bhejte hain — sale screen boot par WOHI order cart mein recall ho.
        // Sirf company-scoped + khula (held/preparing/ready) order pass hota hai;
        // warna param chup-chaap ignore (normal boot). SW-safe: query-string wali
        // navigations network-only hain (SALE_CACHE sirf bina-query URL cache
        // karta hai), is liye fingerprint/cache path par koi asar nahi.
        $recallOrderIdForJs = null;
        if ($request->filled('recall_order') && ($features->kot || $features->tables) && class_exists(RestaurantOrder::class)) {
            $recallCandidate = RestaurantOrder::where('company_id', $companyId)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->find((int) $request->input('recall_order'));
            if ($recallCandidate) {
                $recallOrderIdForJs = (int) $recallCandidate->id;
            }
        }

        // Per-USER grid visibility overrides (owner, 25 Jul 2026): map of
        // "type:id" => 0/1. Empty array until the table exists (prod drift safe
        // — mapForUser is hasTable + try/catch guarded internally).
        $userGridPrefs = \App\Models\PosUserItemPref::mapForUser(auth('pos')->id());

        // Task 1349: ACTIVE counters (terminals) baked for the sale screen's
        // device-level counter picker. Deliberately tiny (id/name/code) — this
        // screen can be served from SALE_CACHE, so the list joins
        // posBootFingerprint() (a newly added counter must refresh cached copies).
        $terminalsForJs = \Schema::hasTable('pos_terminals')
            ? PosTerminal::where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('terminal_name')
                ->get(['id', 'terminal_name', 'terminal_code'])
                ->map(fn ($t) => [
                    'id' => (int) $t->id,
                    'name' => (string) $t->terminal_name,
                    'code' => (string) ($t->terminal_code ?? ''),
                ])->values()->all()
            : [];

        // OFFLINE-FIRST BOOT (Jul 2026): fingerprint baked into the page so a
        // SW-cached copy of this screen can detect staleness via /pos/api/boot-check.
        $bootFp = $this->posBootFingerprint($company, $user);

        // Task #643: baked Order Cancel verdict — the sale screen's board menu,
        // bell-panel Cancel and claimed-cart Cancel all hide on this flag; the
        // deleteOrder server gate re-enforces the SAME verdict.
        $canOrderCancel = \App\Services\PosAccessService::orderCancelAllowed($user, $company);

        // Task 1379: baked KOT-reprint verdict — the bill panel, table-board
        // menu, held/incoming order lists and the post-billing receipt popup
        // all hide their Reprint / Re-send / Last Add-on buttons on this flag;
        // the kitchen-ticket, resend and print-job endpoints re-enforce it.
        $canKotReprint = \App\Services\PosAccessService::kotReprintAllowed($user, $company);
        // Owner 25 Aug 2026: "Aakhri Add-on" ab apne alag company switch par hai —
        // shop khatarnak poora Re-send band kar ke yeh jaiz wala chalu rakh sake.
        $canKotLastAddon = \App\Services\PosAccessService::kotLastAddonAllowed($user, $company);

        return response(view('pos.universal', compact(
            'company', 'features', 'products', 'services', 'categories',
            'recipeLookup', 'tables', 'selectedTable', 'heldOrders',
            'customers', 'taxRate', 'taxRules', 'stockStatus', 'blockOutOfStock',
            'posRole', 'discountLimit', 'hasManagerPin', 'ingredientCosts',
            'lowStockAlerts', 'inventoryEnabled', 'dealsForJs',
            'editBillForJs', 'userGridPrefs', 'bootFp', 'customersTruncated',
            'recallOrderIdForJs', 'canOrderCancel', 'canKotReprint', 'canKotLastAddon', 'terminalsForJs'
        )))
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('X-TaxNest-Sale-Document', 'pra')
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
     *
     * Task 1390: EVERY per-user permission verdict the screen bakes in must be
     * hashed into 'set' — otherwise a cache-first / offline copy (browser or
     * mobile WebView shell) keeps showing controls the server already refuses.
     * tests/Feature/PosBakedPermissionFingerprintTest.php discovers the baked
     * verdicts from this controller and fails if one is missing here.
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
            // Task 431: the Delivery Board button is BAKED into the sale screen —
            // a riders plan-gate change must refresh the offline-cached copy.
            (bool) \App\Services\PosFeatureService::planAllows($company, 'riders_enabled'),
            // Task 643: the Order Cancel verdict is BAKED into the sale screen —
            // toggling the company switch / custom access must refresh the cache.
            (bool) \App\Services\PosAccessService::orderCancelAllowed($user, $company),
            // Task 1379: the KOT-reprint verdict is BAKED into the sale screen —
            // flipping the company switch or a Custom Access tick must refresh
            // the offline-cached copy, or a cached screen keeps showing (and
            // firing) buttons the server now refuses.
            (bool) \App\Services\PosAccessService::kotReprintAllowed($user, $company),
            (bool) \App\Services\PosAccessService::kotLastAddonAllowed($user, $company),
            // Caller ID is BAKED as "switch AND plan/add-on gate". The switch
            // alone rides posConfigRev, but a plan change or an add-on purchase
            // moves the verdict WITHOUT touching the column — fold the resolved
            // answer in, or a cached screen keeps the stale one.
            (bool) \App\Services\PosFeatureService::callerIdLive($company),
            // Task 657: restaurant flags (KOT/tables buttons) are BAKED into the
            // sale screen after the restaurantAllowed() mask — a plan flip
            // (e.g. Starter→Business now unlocks Kitchen mode) must refresh the
            // offline-cached copy even though feature_flags itself is unchanged.
            (bool) \App\Services\PosFeatureService::restaurantAllowed($company),
            // Task 1349: the ACTIVE counter list is BAKED into the sale screen
            // (device counter picker). Adding/renaming/deactivating a counter
            // must refresh the offline-cached copy, or the old screen keeps
            // offering a stale list (and a deleted counter stays selectable).
            // Schema-guarded: minimal test schemas / pre-migration installs may
            // not have the table at all — a missing table must not 500 the screen.
            \Schema::hasTable('pos_terminals')
                ? $agg(PosTerminal::where('company_id', $companyId)->where('is_active', true))
                : null,
        ]));

        $screenPath = resource_path('views/pos/universal.blade.php');
        return [
            'u' => (int) $user->id,
            'c' => (int) $companyId,
            // Task 1347: the active branch is BAKED into the sale screen (it rides
            // every offline bill as offline_branch_id). Switching branch must
            // refresh the cache-first copy, or the new branch keeps billing under
            // the old one. 0 = single-branch / company-wide, i.e. unchanged.
            'b' => (int) (app()->bound('currentBranchId') ? (app('currentBranchId') ?? 0) : 0),
            // Task 658: baked i18n is now a USED-KEYS subset — a lang-file-only
            // edit (blade untouched) must still refresh cached copies, so the
            // 's' rev appends the active-locale pos.php mtimes (+ locale).
            's' => (is_file($screenPath) ? (string) @filemtime($screenPath) : '0')
                . '-' . \App\Support\PosI18n::langRev(),
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
     * Records a client-side sale-screen startup failure without accepting any
     * catalog, customer, or bill data. Support can correlate the authenticated
     * company/user from the log context when a shop reports a blank screen.
     */
    public function bootDiagnostics(Request $request)
    {
        $user = auth('pos')->user();
        Log::warning('POS sale boot diagnostic', [
            'company_id' => app('currentCompanyId'),
            'user_id' => $user?->id,
            'variant' => 'pra',
            'reason' => substr((string) $request->input('reason', 'unknown'), 0, 80),
            'message' => substr((string) $request->input('message', ''), 0, 180),
            'online' => $request->boolean('online'),
            'controlled' => $request->boolean('controlled'),
        ]);
        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
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

        // Stale-form guard, per block (Task 1393 — same shape as the PRA Receipt
        // Settings page, Task 1377). Both blocks below are WHOLESALE rewrites
        // driven by checkbox presence, so a POST from an outdated copy of this
        // page silently switched OFF every feature the copy did not carry.
        // Unchecked checkboxes send nothing, so "stale form" and "everything
        // unticked" look identical on the wire — only a marker can tell them
        // apart. A block is rewritten when THIS request actually carries it: the
        // hidden marker (freshly rendered form) or any of that block's own fields
        // (scripted and legacy POSTs keep working). Otherwise the stored block is
        // left exactly as it is; wiping is never the safe default.
        $fsPresent      = $request->has('fs_present');
        $flagsPresent   = $fsPresent || $request->has('feature_flags');
        $kitchenPresent = $fsPresent || $request->hasAny([
            'auto_print_kot', 'kot_reprint_enabled', 'kot_last_addon_enabled',
            'pos_guided_flow_enabled',
        ]);

        $companyUpdates = [
            'business_category' => $data['business_category'] ?? $company->business_category,
            'pos_ui_density'    => $data['pos_ui_density'] ?? $company->pos_ui_density ?? 'standard',
            // Finishing the wizard marks setup complete so it never auto-launches again.
            'pos_setup_completed' => true,
        ];

        // Manual PRA tax-rate overrides. These are number inputs, so the rendered
        // form ALWAYS submits them — has() therefore separates "submitted blank"
        // (clear the override back to the global default) from "this request never
        // carried the field" (stale form: keep whatever the shop already saved).
        foreach (['pos_tax_rate_cash', 'pos_tax_rate_card'] as $rateField) {
            if ($request->has($rateField)) {
                $companyUpdates[$rateField] = $request->filled($rateField)
                    ? round((float) $request->input($rateField), 2)
                    : null;
            }
        }

        if ($flagsPresent) {
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
            $companyUpdates['feature_flags'] = $flags;
            // Master inventory module switch follows the wizard's
            // "Inventory Tracking" flag so both surfaces always agree.
            $companyUpdates['inventory_enabled'] = (bool) ($flags['inventory'] ?? false);
        }

        // use_universal_pos rides a hidden input, so its absence is unambiguous:
        // only a form that predates the field can omit it. Never reset it blind.
        if ($request->has('use_universal_pos')) {
            $companyUpdates['use_universal_pos'] = (bool) ($data['use_universal_pos'] ?? false);
        }

        if ($kitchenPresent) {
            // Kitchen preferences (checkboxes — absent value = off).
            // NOTE: pos_receipt_show_tax moved to the Receipt Settings page
            // (receiptSettings) — do NOT save it here or every Features save
            // would force it off.
            $companyUpdates['auto_print_kot']          = (bool) $request->input('auto_print_kot', false);
            $companyUpdates['kot_reprint_enabled']     = (bool) $request->input('kot_reprint_enabled', false);
            $companyUpdates['kot_last_addon_enabled']  = (bool) $request->input('kot_last_addon_enabled', false);
            $companyUpdates['pos_guided_flow_enabled'] = (bool) $request->input('pos_guided_flow_enabled', false);
        }

        $company->update($companyUpdates);

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
            // Task 1349: counter (terminal) this billing device is set to. Rides
            // on EVERY sale (online + offline queue) so counter-wise reporting
            // works; resolved/validated below (invalid = NULL, never a block).
            'terminal_id' => 'nullable|integer',
            // Task 646: waiter order loaded into the cart — FINAL bills settle
            // it server-side BEFORE the response so the receipt can print the
            // waiter's name on the very first (auto-)print.
            'incoming_order_id' => 'nullable|integer',
        ]);

        // ── ONLINE-PAYMENT GATE, waiter-order settle path (owner, 26 Aug 2026) ──
        // payOrder is NOT the only way a marked order can go final: the counter
        // can load a waiter's order into the cart and ring it up right here. Same
        // rule, same 422 contract — so the sale screen shows the very same
        // "paisay aa gaye?" confirmation and retries with the flag. Provisionals
        // are exempt: they are not final bills and never settle the waiter order.
        $incomingOrderIdIn = (int) $request->input('incoming_order_id', 0);
        if ($incomingOrderIdIn > 0
            && !$request->boolean('save_as_provisional')
            && !$request->boolean('online_payment_confirmed')
            && \Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'online_payment_awaited_at')
            && \App\Models\RestaurantOrder::where('company_id', $companyId)
                ->where('id', $incomingOrderIdIn)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->whereNotNull('online_payment_awaited_at')
                ->exists()) {
            return response()->json([
                'success' => false,
                'code'    => 'online_payment_awaited',
                'message' => __('pos.online_confirm_body'),
            ], 422);
        }

        // OFFLINE-FIRST replay guard: if an earlier sync attempt already stored
        // this bill (response was lost mid-flight — network dropped again, tab
        // closed, etc.), return the SAME success payload instead of creating a
        // duplicate. withoutGlobalScope so an already-archived bill (day-close
        // ran between attempts) still dedupes. Schema guard covers the brief
        // deploy-before-migrate window on PROD.
        $offlineUuid = trim((string) $request->input('offline_uuid', ''));
        $offlineUuidColumnExists = $offlineUuid !== '' && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'offline_uuid');
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
        $stockItemsForRecipe = $this->expandDealComponentsForStock(array_map(fn ($ri) => [
            'type' => $ri['type'],
            'item_id' => $ri['item_id'],
            'quantity' => (float) $ri['quantity'],
            'unit_price' => (float) $ri['price'],
            'deal_snapshot' => $ri['deal_snapshot'] ?? null,
        ], $companyItems));
        $recipeItemsForStock = array_values(array_filter($stockItemsForRecipe, fn ($item) =>
            ($item['type'] ?? 'product') === 'product'
            && \App\Services\RecipeInventoryService::hasRecipe($companyId, (int) ($item['item_id'] ?? 0))
        ));
        $recipeBranchId = $request->filled('offline_branch_id')
            ? (int) $request->input('offline_branch_id')
            : app(\App\Services\BranchContextService::class)->stampBranchId();
        $recipeStockErrors = \App\Services\RecipeInventoryService::stockErrors($companyId, $recipeItemsForStock, $recipeBranchId);
        if ($recipeStockErrors) {
            return response()->json([
                'success' => false,
                'stock_error' => true,
                'message' => 'Insufficient kitchen stock: ' . implode(', ', $recipeStockErrors),
            ], 400);
        }
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

        // Task 1349: COUNTER (terminal) attribution — the sale screen sends the
        // device's remembered counter with every bill (online + offline replay).
        // Resolve company-scoped + active; anything else stamps NULL instead of
        // failing the sale. A counter deleted/deactivated AFTER a device
        // remembered it (or after an offline bill was queued) must never block
        // billing — same silently-drop convention as rider attribution.
        $terminalId = null;
        if ($request->filled('terminal_id') && \Schema::hasTable('pos_terminals')) {
            $terminalId = PosTerminal::where('company_id', $companyId)
                ->where('id', (int) $request->input('terminal_id'))
                ->where('is_active', true)
                ->value('id');
        }

        // Task 1375: a counter whose drawer has already been counted and closed
        // takes no more bills today — otherwise its frozen difference would be a
        // lie the moment the next sale lands. The OTHER counters are untouched,
        // which is the whole point of a per-counter close. An offline replay is
        // let through on purpose: it was billed while the counter was still
        // open, and refusing it would lose a real sale.
        if ($terminalId && !$request->filled('offline_queued_at')
            && \App\Services\PosCounterDrawer::isClosed($companyId, (int) $terminalId, \App\Services\PosBusinessDay::current((int) $companyId))) {
            $counterName = PosTerminal::where('company_id', $companyId)->where('id', (int) $terminalId)->value('terminal_name');
            $closedMsg = __('pos.counter_sale_blocked', ['counter' => $counterName ?: (__('pos.counter_word') . ' #' . $terminalId)]);
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => $closedMsg, 'message' => $closedMsg], 422);
            }

            return back()->withInput()->with('error', $closedMsg);
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
                        'error' => \App\Services\SubscriptionAccessService::localizedLockReason($quota['reason']),
                        'message' => \App\Services\SubscriptionAccessService::localizedLockReason($quota['reason']),
                        'quota_full' => true,
                        'provisional_allowed' => $provisionalAllowed,
                    ], 403);
                }
                return back()->withInput()->with('error', \App\Services\SubscriptionAccessService::localizedLockReason($quota['reason']));
            }
        }

        // Table-required invariant (owner voice note, 9 Aug 2026): when the company
        // manages tables (tables feature ON), a Dine-In bill needs its table on THIS
        // direct path too — manual/deal carts and crafted requests bypass the
        // restaurant hold flow, and the sale screen always sends table_id for
        // dine-in. Sits AFTER the quota gate (its 403 contract is locked by tests).
        // Exemptions (never strand a bill):
        //   - offline replays (offline_queued_at) — queued before the rule existed,
        //     losing a rung-up bill is far worse than a missing table;
        //   - requests without order_type (older clients / non-restaurant screens).
        if ($request->input('order_type') === 'dine_in' && !$request->filled('table_id') && !$request->filled('offline_queued_at')) {
            $tableFeatures = PosFeatureService::forCompany($company);
            if ($tableFeatures->tables ?? false) {
                $tblMsg = __('pos.dine_in_table_required');
                if ($request->expectsJson()) {
                    return response()->json(['success' => false, 'error' => $tblMsg, 'message' => $tblMsg], 422);
                }
                return back()->withInput()->with('error', $tblMsg);
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

        // ── Billing Scope (owner request 07 Aug 2026) ─────────────────────────
        // Stream lock per staff account. Stream definition MIRRORS the report
        // tabs (applyReportFilters): PRA stream = bill enters the PRA pipeline
        // at birth (pra_status='pending'); local stream = provisionals AND
        // reporting-OFF finals. UI hides the buttons; this guards direct POSTs
        // and offline replays.
        // Task 1186: EXPLICIT scope only — the derived default (visibility)
        // must never block a sale-time write (F9/F10 provisional, promote).
        $billingScope = $posUser->posBillingScopeExplicit();
        if ($billingScope === 'pra' && $initialPraStatus !== 'pending') {
            $scopeMsg = __('pos.billing_scope_pra_only');
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => $scopeMsg, 'message' => $scopeMsg], 403);
            }
            return back()->withInput()->with('error', $scopeMsg);
        }
        if ($billingScope === 'local' && $initialPraStatus === 'pending') {
            $scopeMsg = __('pos.billing_scope_local_only');
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => $scopeMsg, 'message' => $scopeMsg], 403);
            }
            return back()->withInput()->with('error', $scopeMsg);
        }

        // ── Bill Number Style (owner request 07 Aug 2026) ─────────────────────
        // Company chooses per stream: 'serial' (default) ya roz ka 'token'.
        // Token is allocated ONCE at bill birth and frozen on bill_token so
        // reprints never change. Serial (invoice_number) ALWAYS stays underneath
        // — khata/search/returns/PRA sab serial par chalte hain.
        $billTokenFields = [];
        $billStream = $initialPraStatus === 'pending' ? 'pra' : 'local';
        $billStyleCol = $billStream === 'pra' ? 'pra_number_style' : 'local_number_style';
        if (($company->{$billStyleCol} ?? 'serial') === 'token'
            && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'bill_token')) {
            $billToken = \App\Services\OrderTokenService::nextBillToken($companyId, $billStream);
            if ($billToken !== null) {
                $billTokenFields = ['bill_token' => $billToken];
            }
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
        if ($riderId && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_assigned_at')) {
            $riderFields['rider_assigned_at'] = now();
        }
        if ($riderId && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_assignment_revision')) {
            $riderFields['rider_assignment_revision'] = (string) \Illuminate\Support\Str::uuid();
        }

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
                    // Browser sends UTC ISO ("...Z") — convert to app TZ (Asia/Karachi)
                    // or the stored created_at lands 5h early → wrong business day.
                    $qa = \Carbon\Carbon::parse($request->input('offline_queued_at'))->setTimezone(config('app.timezone'));
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
                // final must carry a fiscal serial (PRA must never receive an
                // L-NNN USIN), and a non-PRA bill must not hold a fiscal serial.
                // Both the short "P-036" series and legacy "POS-2026-00035" count.
                $isPosSerial = \App\Services\PosFinalSeries::isFinalSerial($invoiceNumber);
                if ($praEnabled && !$isPosSerial) {
                    $invoiceNumber = $this->generateInvoiceNumber($companyId);
                } elseif (!$praEnabled && $isPosSerial) {
                    $invoiceNumber = $this->generateLocalInvoiceNumber($companyId);
                }
                $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

                $transaction->update([
                    'invoice_number' => $invoiceNumber,
                    'terminal_id' => $terminalId,
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
                    'notes' => RestaurantWaiterController::stripIdentityNote($request->input('kitchen_notes'), auth('pos')->user()),
                ] + $riderFields + $taxInclusiveFields
                  // Draft resume: NEVER overwrite an already-frozen token — a
                  // replayed/duplicate submit must reprint the same number.
                  + (!empty($transaction->bill_token) ? [] : $billTokenFields));

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
                    // Task 1347: stampBranchId() (not the raw currentBranchId binding)
                    // — the owner's company-wide view binds NULL, and a live bill must
                    // still land on a real branch. Offline replay keeps its own branch.
                    'branch_id' => $offlineBranchId ?: app(\App\Services\BranchContextService::class)->stampBranchId(),
                    'terminal_id' => $terminalId,
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
                    'notes' => RestaurantWaiterController::stripIdentityNote($request->input('kitchen_notes'), auth('pos')->user()),
                ] + $riderFields + $taxInclusiveFields + $billTokenFields);
            }

            // Offline sync: stamp the bill with the ORIGINAL (clamped) sale moment.
            // created_at is NOT mass-assignable — set + save explicitly. Applies to
            // both the fresh-create and the resumed-draft finalize paths.
            if ($offlineQueuedAt) {
                $transaction->created_at = $offlineQueuedAt;
                // The creating hook already stamped business_date, but it stamped
                // from "now" (the SYNC moment) — re-stamp from the original
                // sale moment so an offline 1 AM bill lands in the right
                // trading day.
                try {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'business_date')) {
                        $stampDay = \App\Services\PosBusinessDay::forMoment((int) $companyId, $offlineQueuedAt);
                        // A closed day never reopens (its Z-report is final):
                        // if the original trading day was already day-closed,
                        // book the late replay into the CURRENT open day.
                        $alreadyClosed = \App\Models\PosDayCloseReport::where('company_id', $companyId)
                            ->where('report_date', $stampDay)
                            ->exists();
                        $transaction->business_date = $alreadyClosed
                            ? \App\Services\PosBusinessDay::current((int) $companyId)
                            : $stampDay;
                    }
                } catch (\Throwable $e) {
                    // Never block a sync over the stamp — backfill repairs it.
                }
                $transaction->save();
            }

            // PROFIT-FREEZE: snapshot the sale-time cost so profit history stays
            // correct even when a shopkeeper later edits the purchase rate.
            // Mirrors the FBR POS freeze (Task 416 / Task 423, owner decision Aug 2026).
            $hasCostPriceCol = \Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'cost_price');
            $storeCostProductIds = collect($companyItems)->where('type', 'product')->pluck('item_id')->filter()->unique()->values();
            $storeCostMap = ($hasCostPriceCol && $storeCostProductIds->isNotEmpty())
                ? \App\Models\PosProduct::where('company_id', $companyId)
                    ->whereIn('id', $storeCostProductIds)
                    ->get(['id', 'cost_price'])
                    ->keyBy('id')
                : collect();

            foreach ($companyItems as $ri) {
                $itemTaxRate = $ri['isExempt'] ? 0 : $taxRate;
                $itemDiscountShare = $subtotal > 0 ? round($discountAmount * ($ri['lineTotal'] / $subtotal), 2) : 0;
                $itemTaxableAmount = $ri['lineTotal'] - $itemDiscountShare;
                // Inclusive mode: line prices are menu (tax-in) — tax_amount holds the
                // INCLUDED portion, unit_price/subtotal keep the as-entered menu values.
                $itemTaxAmount = $taxInclusive
                    ? \App\Services\PosTaxMath::inclusiveLineTax((float) $itemTaxableAmount, (float) $itemTaxRate, $menuRate)
                    : round($itemTaxableAmount * $itemTaxRate / 100, 2);

                $thirdSchemaExists = \Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'is_third_schedule');
                // Freeze cost snapshot: only product-type items with a known cost.
                $frozenCost = null;
                if ($hasCostPriceCol && $ri['type'] === 'product' && !empty($ri['item_id'])) {
                    $cp = (float) ($storeCostMap[$ri['item_id']]?->cost_price ?? 0);
                    $frozenCost = $cp > 0 ? $cp : null;
                }
                PosTransactionItem::create(array_merge([
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
                ], $thirdSchemaExists ? ['is_third_schedule' => $ri['isThirdSchedule'] ?? false] : [],
                   $hasCostPriceCol ? ['cost_price' => $frozenCost] : []));
            }

            PosPayment::create([
                'transaction_id' => $transaction->id,
                'payment_method' => $request->payment_method,
                'amount' => $totalAmount,
                'reference_number' => $request->reference_number,
            ]);

            // Task 880: settle the linked waiter order INSIDE the transaction so
            // pos_transaction_id is written atomically with the bill commit. Receipt
            // templates query restaurant_orders by pos_transaction_id to show the
            // waiter name — the link must exist before the auto-print chain fires its
            // first receipt render. If settle returns false (order already settled
            // elsewhere / not claimable by this cashier), the entire DB transaction
            // rolls back: no orphaned bill, no silent missing-waiter-line on the slip.
            // FINAL bills only — provisionals are editable/deletable and must never
            // consume the waiter order prematurely (conscious P7 rule).
            $waiterOrderSettled = false;
            if (!$saveAsProvisional && $request->filled('incoming_order_id')) {
                $waiterOrderSettled = RestaurantWaiterController::settleWaiterOrder(
                    $companyId, (int) $request->input('incoming_order_id'), $transaction, auth('pos')->user(),
                    $request->boolean('online_payment_confirmed')
                );
                if (!$waiterOrderSettled) {
                    throw new \RuntimeException(__('pos.waiter_order_already_settled'));
                }
            }

            // Kitchen consumption belongs to the bill transaction. The
            // post-commit inventory call below remains for direct products and
            // is idempotent for these recipe rows.
            \App\Services\RecipeInventoryService::consumeForInvoice(
                $companyId,
                $recipeItemsForStock,
                (int) $transaction->id,
                $invoiceNumber,
                auth('pos')->id(),
                $transaction->branch_id ?? null
            );

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $errMsg = __('pos.failed_create_invoice', ['error' => $e->getMessage()]);
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $errMsg], 500);
            }
            return back()->withInput()->with('error', $errMsg);
        }

        // Task #1106: rider assigned at billing time → instant FCM push.
        // Queued AFTER the commit; fires in app()->terminating() (after the
        // response is flushed) and swallows every failure — the pay path can
        // never be blocked or degraded by push (WhatsApp-extras rule).
        if ($riderId) {
            \App\Services\RiderPushService::queuePush((int) $riderId);
        }

        // Deduct from the RESOLVED items (not raw request): resolved rows carry the
        // frozen deal_snapshot so deal components move stock too (deal lines
        // themselves are type 'deal' → skipped by the deduction loop).
        $stockItems = $stockItemsForRecipe;
        $inventoryResult = PosInventoryController::deductStockForInvoice(
            $companyId,
            $stockItems,
            $transaction->id,
            $invoiceNumber,
            auth('pos')->id(),
            // Per-branch stock (Task 1354): the goods leave the shop that made
            // the bill — take the branch from the transaction, never the session.
            $transaction->branch_id ?? null
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
        // Task 1356 — final-bill KOT safety net signal for the settled waiter
        // order (the only restaurant order this endpoint ever finalises).
        // Post-commit + Throwable-guarded: a signal lookup must NEVER 500 an
        // already-committed bill.
        $settleKotPending = false;
        if ($waiterOrderSettled) {
            try {
                $settledOrder = \App\Models\RestaurantOrder::where('company_id', $companyId)
                    ->find((int) $request->input('incoming_order_id'));
                $settleKotPending = \App\Services\KotPrintService::pendingForFinal($company, $settledOrder);
            } catch (\Throwable $kotE) {
                Log::warning('[PAY] kot_pending lookup failed post-commit: ' . $kotE->getMessage());
            }
        }

        if ($request->wantsJson()) {
            // Task 1036: WhatsApp Bill extras ride the pay response (no extra
            // client fetch) — nulls when feature off / no routable number.
            // Task 1092: post-commit — extras failure (e.g. deploy-window class
            // skew) must never 500 an already-committed bill.
            try {
                $waShare = $transaction->waBillPayload($company);
            } catch (\Throwable $waE) {
                Log::warning('[PAY] waBillPayload failed post-commit (degraded to null extras): ' . $waE->getMessage());
                $waShare = ['wa_phone' => null, 'share_url' => null];
            }
            return response()->json([
                'success' => true,
                'transaction_id' => $transaction->id,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAmount,
                'pra_invoice_number' => $transaction->pra_invoice_number ?? null,
                'pra_status' => $transaction->pra_status ?? null,
                // Task 646: true = waiter order already settled server-side;
                // the client skips its completeIncomingOrder fallback call.
                'waiter_order_settled' => $waiterOrderSettled,
                // Task 1356 — final-bill KOT safety net. Only the waiter-order
                // settle path has a restaurant order here; a plain manual cart
                // reports false (no order rows to stamp, KOT rides the txn).
                // A waiter order whose ticket already printed reports false, so
                // settling it never produces a second slip.
                'kot_pending' => $settleKotPending,
                'kot_order_id' => $settleKotPending ? (int) $request->input('incoming_order_id') : null,
                'wa_phone' => $waShare['wa_phone'],
                'share_url' => $waShare['share_url'],
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

        // Task 1197: isolated cashier edits ONLY their own bill — peer IDs 403
        // even via direct URL (editing peers' bills is manager/owner work).
        if (!$transaction->allowedForCashierIsolationOf(auth('pos')->user())) {
            abort(403);
        }

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', __('pos.cannot_edit_submitted_pra_num', ['number' => $transaction->pra_invoice_number]));
        }

        // Return rows are immutable credit notes (Task 570) — never editable.
        if (($transaction->transaction_type ?? 'sale') === 'return') {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', __('pos.return_bill_immutable'));
        }

        // Edit screen weight fix (ZFC 11k customers, Aug 2026): the old code
        // hydrated EVERY customer here even though the view's customer fields
        // are plain name/phone text inputs — the list was never rendered. Do
        // NOT re-add a full customer bake; if a picker is ever needed, use the
        // sale screen's 500-cap + server-search pattern. Products/services are
        // baked into @json for the item dropdown — only the 3 columns it reads.
        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->where('show_on_sale', true)
            ->orderBy('name')->get(['id', 'name', 'price']);
        $services = PosService::where('company_id', $companyId)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'price']);
        $taxRules = PosTaxRule::effectiveRules($company);
        $terminals = PosTerminal::where('company_id', $companyId)->where('is_active', true)->get(['id', 'terminal_name', 'terminal_code']);

        $transactionItems = $transaction->items->map(fn($item) => [
            'type' => $item->item_type ?? 'product',
            'item_id' => $item->item_id ?? '',
            'name' => $item->item_name ?? '',
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
        ])->values();

        return view('pos.edit-transaction', compact('company', 'transaction', 'transactionItems', 'products', 'services', 'taxRules', 'terminals'));
    }

    public function updateTransaction(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)->with('items')->findOrFail($id);

        // Task 1197: isolated cashier updates ONLY their own bill.
        if (!$transaction->allowedForCashierIsolationOf(auth('pos')->user())) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => __('pos.custom_access_denied')], 403);
            }
            abort(403);
        }

        if ($transaction->pra_invoice_number) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => __('pos.cannot_edit_submitted_pra_num', ['number' => $transaction->pra_invoice_number])], 422);
            }
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', __('pos.cannot_edit_submitted_pra'));
        }

        // Return rows are immutable credit notes (Task 570) — never editable.
        if (($transaction->transaction_type ?? 'sale') === 'return') {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => __('pos.return_bill_immutable')], 422);
            }
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', __('pos.return_bill_immutable'));
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
        // Serial split: a bill headed to PRA must carry a real fiscal serial
        // (PRA must never receive an L-NNN USIN). A local bill KEEPS its L number;
        // a bill that already holds a fiscal serial (short P-036 or legacy
        // POS-2026-00035) never renumbers downward.
        $invoiceNumberEdit = $transaction->invoice_number;

        DB::beginTransaction();
        try {
            // Allocate INSIDE the transaction: PosFinalSeries serializes callers on
            // a row lock, and outside a transaction MySQL drops that lock as soon as
            // the statement ends — two cashiers promoting bills at the same moment
            // would then both be handed the same fiscal serial.
            if ($goingToPra && !\App\Services\PosFinalSeries::isFinalSerial($invoiceNumberEdit)) {
                $invoiceNumberEdit = $this->generateInvoiceNumber($companyId);
            }

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
                'notes' => RestaurantWaiterController::stripIdentityNote($request->input('kitchen_notes'), auth('pos')->user()),
            ]);

            $transaction->items()->delete();

            // PROFIT-FREEZE on edit: re-snapshot cost at edit time so the frozen
            // cost stays current if the product rate changed since the original bill.
            $hasCostPriceColEdit = \Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'cost_price');
            $editCostProductIds = collect($companyItems)->where('type', 'product')->pluck('item_id')->filter()->unique()->values();
            $editCostMap = ($hasCostPriceColEdit && $editCostProductIds->isNotEmpty())
                ? \App\Models\PosProduct::where('company_id', $companyId)
                    ->whereIn('id', $editCostProductIds)
                    ->get(['id', 'cost_price'])
                    ->keyBy('id')
                : collect();

            foreach ($companyItems as $ri) {
                $itemTaxRate = $ri['isExempt'] ? 0 : $taxRate;
                $itemDiscountShare = $subtotal > 0 ? round($discountAmount * ($ri['lineTotal'] / $subtotal), 2) : 0;
                $itemTaxableAmount = $ri['lineTotal'] - $itemDiscountShare;
                $itemTaxAmount = $editTaxInclusive
                    ? \App\Services\PosTaxMath::inclusiveLineTax((float) $itemTaxableAmount, (float) $itemTaxRate, $editMenuRate)
                    : round($itemTaxableAmount * $itemTaxRate / 100, 2);

                $thirdSchemaExistsEdit = \Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'is_third_schedule');
                $frozenCostEdit = null;
                if ($hasCostPriceColEdit && $ri['type'] === 'product' && !empty($ri['item_id'])) {
                    $cpEdit = (float) ($editCostMap[$ri['item_id']]?->cost_price ?? 0);
                    $frozenCostEdit = $cpEdit > 0 ? $cpEdit : null;
                }
                PosTransactionItem::create(array_merge([
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
                ], $thirdSchemaExistsEdit ? ['is_third_schedule' => $ri['isThirdSchedule'] ?? false] : [],
                   $hasCostPriceColEdit ? ['cost_price' => $frozenCostEdit] : []));
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
                // Per-branch stock (Task 1354): both legs of the reconcile ride
                // the BILL's branch so an edit never shuffles stock between shops.
                PosInventoryController::restoreStockForInvoice(
                    $companyId, $oldStockItems, $transaction->id, $transaction->invoice_number, auth('pos')->id(), 'pos_edit', $transaction->branch_id ?? null
                );
                $newStockItems = $this->expandDealComponentsForStock(array_map(fn ($ri) => [
                    'type' => $ri['type'],
                    'item_id' => $ri['item_id'],
                    'quantity' => (float) $ri['quantity'],
                    'unit_price' => (float) $ri['price'],
                    'deal_snapshot' => $ri['deal_snapshot'] ?? null,
                ], $companyItems));
                PosInventoryController::deductStockForInvoice(
                    $companyId, $newStockItems, $transaction->id, $transaction->invoice_number, auth('pos')->id(), $transaction->branch_id ?? null
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

        // Return rows are immutable credit notes (Task 570): deleting one would
        // desync the parent's returned_quantity and un-net the reports.
        if (($transaction->transaction_type ?? 'sale') === 'return') {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', __('pos.return_bill_immutable'));
        }

        DB::beginTransaction();
        try {
            // Re-read under a row lock: a raced / replayed DELETE must not
            // restock twice or bank a second quota ledger row for one bill.
            // (sqlite ignores FOR UPDATE — MySQL is where the race lives.)
            $locked = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('id', $transaction->id)
                ->lockForUpdate()
                ->first();
            if (!$locked) {
                DB::rollBack();
                return redirect()->route('pos.transactions')
                    ->with('success', __('pos.invoice_deleted_success', ['number' => $transaction->invoice_number]));
            }

            // QUOTA LEDGER (Task 1372) — write it BEFORE the row disappears.
            // A deleted bill that already consumed this month's quota must keep
            // consuming it (same rule as the day-close DELETE policy), otherwise
            // deleting bills one by one buys the shop free monthly bills.
            $this->recordBillDeletionForQuota($locked, $companyId);

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
                    $companyId, $restoreItems, $transaction->id, $transaction->invoice_number, auth('pos')->id(), 'pos_void', $transaction->branch_id ?? null
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
     * Bank a hard-deleted bill against the monthly quota (Task 1372).
     *
     * PlanLimitService::canCreatePosBill counts the FINAL bills still present in
     * pos_transactions, so a delete silently returns the slot. The batch deletes
     * (day-close DELETE policy, clear archived local bills) each persist their
     * own deleted_final_count; the per-bill admin delete writes one
     * pos_bill_deletions row here and PlanLimitService adds it back in.
     *
     * Recorded ONLY when the bill was actually consuming quota — the predicate
     * mirrors that live count exactly (completed + invoice_mode not 'local' +
     * not a return). Deliberate provisionals and drafts never consumed a slot,
     * and returns never do either, so they leave no row. The month is NOT
     * decided here: sold_at carries the bill's created_at and the service bounds
     * it, so deleting a PREVIOUS month's bill can never touch this month's count.
     *
     * Must run INSIDE deleteTransaction's DB transaction (and after its row
     * lock) so a rollback can never leave a phantom row behind.
     */
    private function recordBillDeletionForQuota(PosTransaction $transaction, int $companyId): void
    {
        if ($transaction->status !== 'completed'
            || $transaction->invoice_mode === 'local'
            || ($transaction->transaction_type ?? 'sale') === 'return') {
            return;
        }

        // Table/Schema-guarded for the deploy-before-migrate window on PROD:
        // a missing ledger must never block an admin from deleting a bill.
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_bill_deletions')) {
                return;
            }
            \App\Models\PosBillDeletion::firstOrCreate(
                ['company_id' => $companyId, 'transaction_id' => $transaction->id],
                [
                    'invoice_number' => $transaction->invoice_number,
                    'sold_at' => $transaction->created_at,
                    'business_date' => $transaction->business_date,
                    'total_amount' => $transaction->total_amount,
                    'deleted_by' => auth('pos')->id(),
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('POS bill delete quota ledger failed', [
                'company_id' => $companyId,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Race-safe local→final promotion for retryPra: locks + re-verifies the bill is
     * still a genuine provisional (triple completed/local/local) INSIDE the transaction
     * before allotting the POS serial — a double-POST (or a race with
     * apiPromoteProvisional) must never renumber twice or clobber a bill already
     * queued/submitted to PRA. Returns false when the bill is no longer promotable.
     * $newPraStatus: 'pending' (reporting ON, will submit) or null (reporting OFF final).
     */
    /**
     * Bill Number Style (07 Aug 2026): when a bill JOINS the PRA pipeline
     * (pra_status → 'pending'), its display token must follow the PRA stream's
     * style — a fresh PRA daily token, or NULL when the style is 'serial'
     * (never keep a stale LOCAL token on a PRA bill: reprints would show a
     * number that can collide with a real PRA token).
     */
    private function praStreamBillTokenFields(int $companyId): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'bill_token')) {
            return [];
        }
        $company = Company::find($companyId);
        if (($company->pra_number_style ?? 'serial') !== 'token') {
            return ['bill_token' => null];
        }
        return ['bill_token' => \App\Services\OrderTokenService::nextBillToken($companyId, 'pra')];
    }

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
                ] + ($newPraStatus === 'pending' ? $this->praStreamBillTokenFields($companyId) : []));
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
                    'invoice_number' => \App\Services\PosFinalSeries::isFinalSerial($locked->invoice_number)
                        ? $locked->invoice_number
                        : $this->generateInvoiceNumber($companyId),
                    'pra_status' => 'pending',
                    'invoice_mode' => 'pra',
                    'pra_response_code' => null,
                    'is_archived' => false,
                    'archived_at' => null,
                ] + $this->praStreamBillTokenFields($companyId));
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

        // Billing Scope (07 Aug 2026): local-scoped staff never touch the PRA
        // pipeline. Task 1186: EXPLICIT scope only — retry is a write path.
        if (auth('pos')->user()?->posBillingScopeExplicit() === 'local') {
            return back()->with('error', __('pos.billing_scope_local_only'));
        }

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

        // Task 1197: isolated cashier retries ONLY their own bill.
        if (!$transaction->allowedForCashierIsolationOf(auth('pos')->user())) {
            return back()->with('error', __('pos.custom_access_denied'));
        }

        if ($transaction->pra_invoice_number) {
            return back()->with('error', __('pos.invoice_already_submitted_pra_num', ['number' => $transaction->pra_invoice_number]));
        }

        if ($transaction->pra_status === 'submitted') {
            return back()->with('error', __('pos.invoice_already_submitted_pra'));
        }

        // Return / credit-note rows (Task 570): retryable ONLY when the parent
        // bill actually has a PRA fiscal number — a credit note against a USIN
        // PRA never saw is meaningless. Local returns stay local by design.
        if (($transaction->transaction_type ?? 'sale') === 'return') {
            // Returns are manager+ only (mirrors PosReturnController::gate):
            // cashiers see the row in the bills panel but cannot retry it.
            if (auth('pos')->user()?->posCashierBlocked()) {
                return back()->with('error', __('pos.return_manager_only'));
            }
            $returnParent = $transaction->parent_transaction_id
                ? PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->find($transaction->parent_transaction_id)
                : null;
            if (empty($returnParent?->pra_invoice_number)) {
                return back()->with('error', __('pos.return_parent_not_reported'));
            }
            if (!in_array($transaction->pra_status, ['pending', 'failed', 'offline'], true)) {
                return back()->with('error', __('pos.invoice_cannot_submit_status', ['status' => $transaction->pra_status ?? 'local']));
            }
            // ENTERPRISE SAFE MODE (Task 637): Agent-Sync companies never curl PRA
            // from the server (US IP = guaranteed ~8s timeout). Credit notes re-queue
            // as 'pending' just like the non-return branch below; the desktop agent
            // picks them up on its next poll.
            if ($company->agentHandlesPra()) {
                $transaction->update(['pra_status' => 'pending', 'pra_response_code' => null]);
                return back()->with('success', __('pos.requeued_desktop_agent'));
            }
            $praService = new PraIntegrationService($company);
            $result = $praService->sendInvoice($transaction);
            if (!empty($result['success'])) {
                return back()->with('success', __('pos.invoice_submitted_pra_num', ['number' => $transaction->fresh()->pra_invoice_number]));
            }
            return back()->with('error', $result['message'] ?? __('pos.pra_submission_failed'));
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
                return back()->with('error', \App\Services\SubscriptionAccessService::localizedLockReason($quota['reason']));
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

    /**
     * Re-queue a single historical exempt_internal bill as 'pending' so the
     * Desktop Agent (or direct server) can submit it to PRA at TaxRate 0.
     *
     * Safety mirrors the artisan pra:requeue-exempt-internal command:
     *   • company-scoped (never cross-tenant)
     *   • WHERE pra_status='exempt_internal' only (never touches other statuses)
     *   • WHERE pra_invoice_number IS NULL (never overwrites a submitted row)
     *
     * Owner/admin only — cashiers and managers cannot trigger this.
     */
    public function requeueExemptInternal($id)
    {
        $companyId = app('currentCompanyId');
        $company   = Company::find($companyId);

        // Task 818 review: owner/pos_admin ONLY — managers are admin-equivalent
        // elsewhere (isPosAdmin) but must NOT push bills into the fiscal pipeline.
        if (!auth('pos')->user()?->canRequeueExemptPra()) {
            return back()->with('error', __('pos.only_owner_requeue_exempt'));
        }

        $transaction = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->findOrFail($id);

        // Safety double-check: must still be exempt_internal with no fiscal number.
        if ($transaction->pra_status !== PosTransaction::EXEMPT_INTERNAL
            || !empty($transaction->pra_invoice_number)) {
            return back()->with('error', __('pos.requeue_exempt_not_eligible'));
        }

        // Flip to 'pending' — sendInvoice blocks exempt_internal but NOT pending,
        // so setting the status first then calling submit is safe.
        $transaction->update([
            'pra_status'       => 'pending',
            'pra_response_code' => null,
        ]);

        // Reporting-OFF check comes first — applies to both agent-mode and
        // direct-server shops. The bill is already queued (pending); surfacing
        // "queued, will submit when reporting turns back on" is correct for
        // both modes and avoids the misleading "agent will pick it up" banner
        // on an agent shop where reporting is actually disabled.
        if (!$company->praReportingActive()) {
            return back()->with('success', __('pos.requeue_exempt_queued_reporting_off', [
                'invoice' => $transaction->invoice_number,
            ]));
        }

        // Agent-mode companies with reporting ON: the Desktop Agent picks it up
        // on its next poll — do not attempt a direct-server submission here.
        if ($company->agentHandlesPra()) {
            return back()->with('success', __('pos.requeue_exempt_success_agent', [
                'invoice' => $transaction->invoice_number,
            ]));
        }

        // Direct-server mode with reporting ON: attempt an immediate PRA submission.
        try {
            $praService = new PraIntegrationService($company);
            $result = $praService->sendInvoice($transaction);
            $transaction->refresh();

            if (!empty($result['success'])) {
                return back()->with('success', __('pos.pra_submission_successful_num', [
                    'number' => $transaction->pra_invoice_number ?? 'N/A',
                ]));
            }
            return back()->with('error', __('pos.pra_submission_failed', [
                'error' => $result['message'] ?? __('pos.unknown_error'),
            ]));
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

        // Billing Scope (07 Aug 2026): local-scoped staff cannot push anything
        // into the PRA pipeline — mirrors retryPra / apiRetryFailed.
        // Task 1186: EXPLICIT scope only — bulk retry is a write path.
        if ((auth('pos')->user()?->posBillingScopeExplicit() ?? 'both') === 'local') {
            return back()->with('error', __('pos.billing_scope_local_only'));
        }

        $bulkQuery = PosTransaction::where('company_id', $companyId)
            ->whereIn('pra_status', ['failed', 'offline', 'pending'])
            ->whereNull('pra_invoice_number');
        // Task 582: return/credit-note rows are manager+ only — a cashier's
        // bulk "Sync all" must never sweep them into the PRA pipeline.
        if (auth('pos')->user()?->posCashierBlocked()
            && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type')) {
            $bulkQuery->where(function ($q) {
                $q->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
            });
        }
        // Task 1197: an isolated cashier's bulk "Sync all" sweeps ONLY their own bills.
        $bulkQuery->tap(fn ($q) => PosTransaction::applyCashierIsolation($q, auth('pos')->user()));
        $pendingInvoices = $bulkQuery->orderBy('id', 'asc')->get();

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
        // Prepaid conversion stamp — PROD-drift guarded (older installs may not
        // have run the prepaid-conversion migration yet).
        $hasPrepaidCol = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'prepaid_converted_at');
        // Current business day (00:00–05:59 counts in yesterday) — the Pending
        // Deliveries badge filters bills to THIS date client-side, and Task 524
        // stamps purani unassigned bills server-side against the same date.
        $bizToday = $hasBizDate ? \App\Services\PosBusinessDay::current($companyId) : null;
        // Billing Scope (07 Aug 2026): pra-scoped staff never see local/provisional
        // bills — the provisional half of this list is emptied for them. The FINAL
        // delivery-bill half below stays (delivery tracking is stream-agnostic).
        // Task 1186: EXPLICIT scope only — this list feeds the F10 provisional
        // modal; a derived-'pra' (reporting-ON) cashier's own provisionals must
        // keep appearing here or the F10 workflow breaks.
        $scopeHidesProvisionals = (auth('pos')->user()?->posBillingScopeExplicit() ?? 'both') === 'pra'
            // Task 705: manager default PRA-only — provisional list hidden too.
            || (auth('pos')->user()?->posHidesLocalStream() ?? false);
        $bills = $scopeHidesProvisionals ? collect() : PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('invoice_mode', 'local')
            ->where('pra_status', 'local')
            // Task 1197: an isolated cashier sees only their OWN provisionals
            // in the F10 modal. The FINAL delivery-bill half below stays
            // SHARED deliberately — delivery tracking / rider settle is a
            // shared workflow (mirrors the Task 807/1186 stream-agnostic
            // decision; isolating it would break rider handover).
            ->tap(fn ($q) => PosTransaction::applyCashierIsolation($q, auth('pos')->user()))
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
        // Task 807: stream-scope for final delivery bills — mirrors
        // PosRiderController::applyStreamScope (EXPLICIT scope, Task 1186:
        // delivery tracking stays stream-agnostic for derived-default staff —
        // hiding a derived-'pra' cashier's own delivery provisionals/finals
        // here would break the delivery workflow).
        // local-scoped staff see ONLY
        // local finals; pra-scoped staff see ONLY PRA finals; 'both'
        // (owner/admin/pos_delivery) sees everything unchanged.
        $finalBillScope = auth('pos')->user()?->posBillingScopeExplicit() ?? 'both';
        if ($hasRiderCols && $hasDelStatus) {
            $finalBills = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('status', 'completed')
                // NOT a provisional (local+local triple = provisional definition).
                ->whereNot(function ($q) {
                    $q->where('invoice_mode', 'local')->where('pra_status', 'local');
                })
                ->where(function ($q) {
                    $q->where(function ($qa) {
                        $qa->whereNotNull('rider_id')
                            ->where(function ($qb) {
                                // Abhi raste mein… (Task 523: settle_all in
                                // statuses ko bhi settle kar deta hai —
                                // settled bill popup se ghayab, FBR fix mirror)
                                $qb->where(function ($q1) {
                                        $q1->whereIn('delivery_status', ['assigned', 'dispatched'])
                                           ->whereNull('rider_settlement_id');
                                    })
                                    // …ya deliver ho gaya par cash abhi rider ke paas.
                                    ->orWhere(function ($q2) {
                                        $q2->where('delivery_status', 'delivered')
                                           ->where('payment_method', 'cash')
                                           ->whereNull('rider_settlement_id');
                                    });
                            });
                    })
                    // Task 513: UNASSIGNED delivery bills (rider NULL, status NULL,
                    // unsettled) bhi popup mein — cashier rider yahin se assign kare,
                    // Deliveries board kholne ki zaroorat na rahe. Same 7-din window
                    // as the Deliveries board pending tab (Task 512): purane
                    // pre-feature delivery bills popup ko flood na karein.
                    ->orWhere(function ($qu) {
                        $qu->whereNull('rider_id')
                            ->whereNull('delivery_status')
                            ->whereNull('rider_settlement_id')
                            ->where('order_type', 'delivery')
                            ->where('created_at', '>=', now()->subDays(7));
                    });
                })
                // Task 807: apply billing-stream predicate — same logic as
                // PosRiderController::applyStreamScope.
                ->when($finalBillScope === 'local', function ($q) {
                    $q->where(function ($s) {
                        $s->where('invoice_mode', 'local')
                          ->orWhere(function ($s2) {
                              $s2->whereNull('pra_status')->whereNull('pra_invoice_number');
                          });
                    });
                })
                ->when($finalBillScope === 'pra', function ($q) {
                    $q->where(function ($s) {
                        $s->where(function ($s2) {
                            $s2->where('invoice_mode', '!=', 'local')->orWhereNull('invoice_mode');
                        })->where(function ($s2) {
                            $s2->whereNotNull('pra_status')->orWhereNotNull('pra_invoice_number');
                        });
                    });
                })
                ->orderBy('id', 'desc')
                ->limit(50)
                ->get(['id', 'invoice_number', 'customer_name', 'customer_phone', 'order_type', 'delivery_address', 'total_amount', 'payment_method', 'created_at', 'rider_id', 'rider_settlement_id', 'delivery_status',
                       ...($hasBizDate ? ['business_date'] : []),
                       // Owner video (25 Aug 2026): the popup now offers the same
                       // Prepaid / Back-to-Cash pair as the Deliveries board, so it
                       // needs the conversion stamp to tell a converted bill from
                       // one that was never cash. Column is PROD-drift guarded.
                       ...($hasPrepaidCol ? ['prepaid_converted_at'] : [])]);
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
                    ->selectRaw('rider_id, COUNT(*) as c, COALESCE(SUM(' . \App\Models\PosRider::remainingExpr('pos_transactions') . '),0) as amt')
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
        // Task 1368: whether that ticket REALLY fired is decided by the line
        // stamps on the bill's restaurant order — the sale screen saves most
        // delivery provisionals through the internal hold → pay pass-through, so
        // they do have one, and its kot_sent_at is stamped at hold whether or not
        // anything printed (see KotPrintService::deliveryPromoteKot). Only the
        // linked ids are fetched here: one extra query for the whole page, and
        // only for the unstamped delivery rows of a toggle-ON shop.
        $kotCompany = Company::find($companyId);
        $kotAfterPayment = (bool) ($kotCompany?->delivery_kot_after_payment ?? false);
        $kotOrders = [];
        if ($kotAfterPayment && \Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'pos_transaction_id')) {
            $kotTxnIds = $bills->filter(fn ($b) => $b->order_type === 'delivery' && empty($b->kot_sent_at))
                ->pluck('id')->all();
            if ($kotTxnIds) {
                $kotOrders = RestaurantOrder::where('company_id', $companyId)
                    ->whereIn('pos_transaction_id', $kotTxnIds)
                    ->where('status', '!=', 'cancelled')
                    ->orderBy('id')
                    ->get(['id', 'pos_transaction_id'])
                    ->keyBy('pos_transaction_id')
                    ->all();
            }
        }

        $data = $bills->map(function ($b) use ($kotCompany, $kotOrders, $hasRiderCols, $hasBizDate, $riderNames, $riderOpen) {
            $kot = \App\Services\KotPrintService::deliveryPromoteKot($kotCompany, $b, $kotOrders[$b->id] ?? null);
            return [
                'id'               => $b->id,
                'invoice_number'   => $b->invoice_number,
                'customer_name'    => $b->customer_name,
                'customer_phone'   => $b->customer_phone,
                'order_type'       => $b->order_type,
                'delivery_address' => $b->delivery_address,
                'total_amount'     => (float) $b->total_amount,
                'payment_method'   => $b->payment_method,
                // Wording comes from the ONE label authority (owner, 26 Aug
                // 2026) — the sale screen used to tidy the raw stored value in
                // JS and printed "QR PAYMENT" where every other screen said
                // "Online / QR".
                'payment_label'    => \App\Support\PosPaymentLabels::label($b->payment_method),
                'items_count'      => PosTransactionItem::where('transaction_id', $b->id)->count(),
                'created_human'    => $b->created_at?->diffForHumans(),
                'created_at'       => $b->created_at?->toDateTimeString(),
                'created_time'     => $b->created_at?->format('h:i A'),
                'kot_pending'      => $kot['pending'],
                // Task 1368: delta target — the sale screen prints ONLY the lines
                // the kitchen never saw off this order. null = order-less bill,
                // whose ticket is rendered from the transaction instead.
                'kot_order_id'     => $kot['order_id'],
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
        // Owner video (25 Aug 2026): "yeh Prepaid aur search wala option yahan
        // (popup mein) nahi aa sakta?" — the Deliveries board's Prepaid /
        // Back-to-Cash pair is now offered inside the Pending Deliveries popup
        // too. The VERDICT is computed here, per bill, mirroring
        // PosRiderController::markPrepaid / unmarkPrepaid exactly (role,
        // delivery+rider context, cash-only, unsettled, not returned) — the
        // popup must never show a button the POST will refuse.
        $canPrepaidRole = in_array(auth('pos')->user()->pos_role ?? '', ['pos_admin', 'pos_manager'], true);
        $finalData = $finalBills->map(function ($b) use ($hasBizDate, $bizToday, $riderNames, $riderOpen, $canPrepaidRole, $hasPrepaidCol) {
            // Task 524: purana (pichhle business day ka) UNASSIGNED bill — popup
            // ise alag collapsed "Purani deliveries" group mein dikhata hai aur
            // badge ki ginti se bahar rakhta hai. Flag SERVER par banta hai
            // (authoritative business day), client sirf parhta hai.
            $billDay = ($hasBizDate && $b->business_date)
                ? (string) $b->business_date
                : $b->created_at?->format('Y-m-d');
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
                // Wording comes from the ONE label authority (owner, 26 Aug
                // 2026) — the sale screen used to tidy the raw stored value in
                // JS and printed "QR PAYMENT" where every other screen said
                // "Online / QR".
                'payment_label'    => \App\Support\PosPaymentLabels::label($b->payment_method),
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
                // Task 524: purani unassigned = collapsed group + badge se bahar.
                'is_stale_unassigned' => (bool) ($bizToday && $billDay && !$b->rider_id
                    && !$b->delivery_status && $billDay < $bizToday),
                // Prepaid pair (owner video, 25 Aug 2026) — verdicts mirror
                // PosRiderController::markPrepaid / unmarkPrepaid. A cash bill
                // still on a rider's open khata can be flipped to "paid online";
                // one that WAS flipped (conversion stamp) can be put back to cash.
                'is_prepaid_converted' => (bool) ($hasPrepaidCol && !empty($b->prepaid_converted_at)),
                'can_mark_prepaid' => (bool) ($canPrepaidRole
                    && $b->order_type === 'delivery'
                    && $b->rider_id
                    && $b->payment_method === 'cash'
                    && empty($b->rider_settlement_id)
                    && $b->delivery_status !== 'returned'),
                'can_unmark_prepaid' => (bool) ($canPrepaidRole
                    && $hasPrepaidCol && !empty($b->prepaid_converted_at)
                    && $b->rider_id
                    && empty($b->rider_settlement_id)
                    && $b->delivery_status !== 'returned'),
            ];
        });

        // Task 513: active riders list + assign permission — the popup renders a
        // rider dropdown on UNASSIGNED bills (POST pos.deliveries.assign, same
        // backend as the board). UI-gating mirrors the route gate: custom-access
        // 'deliveries' verdict (deliveriesFallbackOpen included via customAllows).
        // Plan gate (riders = Pro+) + Delivery feature toggle mirror the board's
        // deliveryGate(); custom-access verdict mirrors PosAuth's route gate.
        $assignCompany = Company::find($companyId);
        $canAssignRider = \App\Services\PosFeatureService::planAllows($assignCompany, 'riders_enabled')
            && !empty(\App\Services\PosFeatureService::forCompany($assignCompany)->delivery)
            && \App\Services\PosAccessService::customAllows(auth('pos')->user(), 'deliveries') !== false
            // Task 797: local-scoped staff cannot assign riders to PRA finals —
            // the assign POST already 403s them server-side; this removes the
            // false UI affordance (dropdown appears but always errors).
            // Task 1186: EXPLICIT scope — a derived-'local' (reporting-OFF)
            // cashier keeps the rider dropdown for their own local finals.
            && (auth('pos')->user()?->posBillingScopeExplicit() ?? 'both') !== 'local';
        $assignRiders = [];
        if ($canAssignRider && $hasRiderCols && \Illuminate\Support\Facades\Schema::hasTable('pos_riders')) {
            // Task 1132/1138: ship last_battery_pct (+ on_duty) so the popup
            // dropdown can flag a rider whose phone may die mid-delivery
            // (🪫 ≤20%, on-duty, last heartbeat ≤6 h). hasColumn-guarded —
            // PROD drift rule; old APKs report NULL. Task 1138: freshness gate
            // via last_located_at (same 6-hour window as the distance hint);
            // a frozen reading from hours ago suppressed rather than shown stale.
            $riderCols = ['id', 'name'];
            $hasBatteryPct = \Illuminate\Support\Facades\Schema::hasColumn('pos_riders', 'last_battery_pct')
                && \Illuminate\Support\Facades\Schema::hasColumn('pos_riders', 'on_duty');
            $hasBatteryLocatedAt = $hasBatteryPct
                && \Illuminate\Support\Facades\Schema::hasColumn('pos_riders', 'last_located_at');
            if ($hasBatteryPct) {
                $riderCols[] = 'last_battery_pct';
                $riderCols[] = 'on_duty';
            }
            if ($hasBatteryLocatedAt) {
                $riderCols[] = 'last_located_at';
            }
            $assignRiders = \DB::table('pos_riders')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get($riderCols)
                ->map(function ($r) use ($hasBatteryPct, $hasBatteryLocatedAt) {
                    $batteryFresh = $hasBatteryLocatedAt
                        && !empty($r->last_located_at)
                        && abs(\Carbon\Carbon::parse($r->last_located_at)->diffInMinutes(now())) <= 360;
                    return [
                        'id' => (int) $r->id,
                        'name' => $r->name,
                        'battery_pct' => ($hasBatteryPct && $batteryFresh
                            && $r->last_battery_pct !== null && $r->on_duty)
                            ? (int) $r->last_battery_pct : null,
                    ];
                })
                ->values()
                ->all();
        }

        return response()->json([
            'success' => true,
            'count'   => $data->count(),
            'bills'   => $data,
            'final_deliveries' => $finalData,
            'riders'  => $assignRiders,
            'can_assign_rider' => $canAssignRider,
            // Current business day (00:00–05:59 counts in yesterday) — the
            // Pending Deliveries badge filters bills to THIS date client-side.
            'business_today' => $bizToday,
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
                if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_archived')) {
                    $q->where('is_archived', false)
                      ->orWhereNull('is_archived')
                      ->orWhere('invoice_mode', 'local');
                }
            })
            ->where('business_date', \App\Services\PosBusinessDay::current($companyId))
            // Billing Scope (07 Aug 2026): stream-locked staff get ONLY their own
            // stream in the Reprint list — mirrors applyReportFilters' split.
            ->where(function ($q) {
                // Single predicate (Task 647): exempt bills visible to both scopes.
                // Task 705: manager default PRA-only = effective 'pra' scope here.
                $tbUser = auth('pos')->user();
                $tbScope = $tbUser?->posBillingScope() ?? 'both';
                if ($tbScope === 'both' && ($tbUser?->posHidesLocalStream() ?? false)) {
                    $tbScope = 'pra';
                }
                PosTransaction::applyBillingScopeFilter($q, $tbScope);
                // Task 1186 own-bill exemption (derived default only): the
                // cashier's own bills — e.g. a reporting-ON cashier's F10
                // provisionals — always appear in their Reprint list.
                if ($tbUser && $tbUser->posBillingScopeIsDerived()) {
                    $q->orWhere('created_by', $tbUser->id);
                }
            })
            // Task 1197: isolated cashier's Reprint list = OWN bills only.
            // ANDs with the scope closure above (compose, never replace).
            ->tap(fn ($q) => PosTransaction::applyCashierIsolation($q, auth('pos')->user()))
            ->orderBy('id', 'desc')
            ->limit(300)
            ->get(['id', 'invoice_number', 'pra_invoice_number', 'customer_name', 'customer_phone', 'total_amount', 'payment_method', 'order_type', 'invoice_mode', 'pra_status', 'created_at']);

        // Task 1036: WhatsApp Bill from the Reprint list — per-bill routable
        // number (null when feature off / unroutable → client hides the action).
        $company = Company::find($companyId);
        $waBillOn = $company
            && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_whatsapp_bill_enabled')
            && $company->pos_whatsapp_bill_enabled
            && \App\Services\PosFeatureService::planAllows($company, 'whatsapp_enabled');

        // Table name per bill (dine-in): batch lookup via restaurant_orders →
        // restaurant_tables so the Reprint list can show "Dine-in • Table 5".
        // One IN query — no N+1 on the 300-bill list.
        $tableByTx = [];
        if ($bills->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('restaurant_orders') && \Illuminate\Support\Facades\Schema::hasTable('restaurant_tables')) {
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

        $data = $bills->map(function ($b) use ($tableByTx, $waBillOn) {
            // Badge resolution mirrors the Transactions-page tab split: the
            // ACTUAL PRA outcome decides, not invoice_mode alone.
            if (!empty($b->pra_invoice_number)) {
                $badge = 'pra';           // fiscal number issued = PRA-reported
            } elseif ($b->pra_status === PosTransaction::EXEMPT_INTERNAL) {
                $badge = 'exempt';        // all-exempt bill — never reported (Task 647)
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
                'payment_label'      => \App\Support\PosPaymentLabels::label($b->payment_method),
                'order_type'         => $b->order_type,
                'table_number'       => $tableByTx[$b->id] ?? null,
                'badge'              => $badge,
                'created_time'       => $b->created_at?->format('h:i A'),
                'created_human'      => $b->created_at?->diffForHumans(),
                // Task 1036: normalized WhatsApp number (share link mints on
                // demand). FINAL bills only — a deliberate provisional is still
                // editable, so it must never be WhatsApp-shareable ($badge
                // mirrors exactly what the row shows the cashier).
                'wa_phone'           => ($waBillOn && $badge !== 'provisional') ? \App\Services\PkPhone::normalize($b->customer_phone) : null,
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
                    $companyId, $restoreItems, $tx->id, $tx->invoice_number, auth('pos')->id(), 'pos_void', $tx->branch_id ?? null
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
                ] + ($reportingOn ? $this->praStreamBillTokenFields($companyId) : []));

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

        // Billing Scope (07 Aug 2026): send_to_pra promote = PRA pipeline entry
        // (blocked for local-scoped staff); LOCAL FINAL = local-stream action
        // (blocked for pra-scoped staff — they never touch local bills).
        // Task 1186: EXPLICIT scope only — promote is a write path; the derived
        // default must never 403 a reporting-ON cashier promoting their own
        // provisional (or a reporting-OFF cashier's LOCAL FINAL).
        $promoScope = auth('pos')->user()?->posBillingScopeExplicit() ?? 'both';
        if ($request->boolean('send_to_pra', true) && $promoScope === 'local') {
            return response()->json(['success' => false, 'message' => __('pos.billing_scope_local_only')], 403);
        }
        if (!$request->boolean('send_to_pra', true) && $promoScope === 'pra') {
            return response()->json(['success' => false, 'message' => __('pos.billing_scope_pra_only')], 403);
        }

        // Task 1197: isolated cashier promotes/finalizes ONLY their own
        // provisional — a peer's ID gets 403 BEFORE any state change (guards
        // BOTH branches below: LOCAL FINAL and send-to-PRA promote). created_by
        // never changes, so a pre-check is race-safe here.
        $isoUser = auth('pos')->user();
        if ($isoUser && $isoUser->posSalesIsolated()) {
            $isoTx = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)->where('id', $id)->first(['id', 'created_by']);
            if ($isoTx && !$isoTx->allowedForCashierIsolationOf($isoUser)) {
                return response()->json(['success' => false, 'message' => __('pos.custom_access_denied')], 403);
            }
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
            return response()->json(['success' => false, 'message' => \App\Services\SubscriptionAccessService::localizedLockReason($quota['reason'])], 403);
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
        // Task 1036: promoted bill is now FINAL — WhatsApp Bill extras ride every
        // success variant below (delivery-clear/promote is a final-bill path too).
        $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->first();
        // Task 1092: post-commit — extras failure must never fail the promote.
        try {
            $waShare = $tx ? $tx->waBillPayload($company) : ['wa_phone' => null, 'share_url' => null];
        } catch (\Throwable $waE) {
            Log::warning('[PAY] waBillPayload failed post-commit (degraded to null extras): ' . $waE->getMessage());
            $waShare = ['wa_phone' => null, 'share_url' => null];
        }

        if (!$reportingOn) {
            return response()->json([
                'success'        => true,
                'submitted'      => false,
                'invoice_number' => $newNumber,
                'total_amount'   => $newTotal,
                'message'        => __('pos.bill_now_final_pra_off_amount', ['number' => $newNumber, 'amount' => number_format($newTotal)]),
                'id'             => $id,
                'wa_phone'       => $waShare['wa_phone'],
                'share_url'      => $waShare['share_url'],
            ]);
        }

        // Agent Sync mode: just leave it queued — desktop agent picks it up within 10s.
        if ($company->agentHandlesPra()) {
            return response()->json([
                'success'        => true,
                'queued'         => true,
                'invoice_number' => $newNumber,
                'total_amount'   => $newTotal,
                'message'        => __('pos.bill_requeued_agent', ['number' => $newNumber]),
                'id'             => $id,
                'wa_phone'       => $waShare['wa_phone'],
                'share_url'      => $waShare['share_url'],
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
                    'wa_phone'       => $waShare['wa_phone'],
                    'share_url'      => $waShare['share_url'],
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
     * Task 655 — lightweight PRA status probe for the payment-complete popup.
     * Agent-mode companies save bills as pra_status='pending' (Desktop Agent
     * submits within seconds); the sale screen polls this to flip the popup
     * badge to PRA VERIFIED + refresh the receipt iframe once the fiscal
     * number lands. Read-only, cashiers allowed, no-store (never SW-cached).
     */
    public function apiPraStatus($id)
    {
        $companyId = app('currentCompanyId');
        $tx = PosTransaction::where('company_id', $companyId)
            ->select(['id', 'pra_status', 'pra_invoice_number'])
            ->find($id);
        if (!$tx) {
            return response()->json(['success' => false], 404);
        }
        // Task 1197: a peer bill's fiscal state is invisible to an isolated
        // cashier — mirror not-found so the endpoint is no existence oracle.
        if (!$tx->allowedForCashierIsolationOf(auth('pos')->user())) {
            return response()->json(['success' => false], 404);
        }
        return response()->json([
            'success' => true,
            'pra_status' => $tx->pra_status,
            'pra_invoice_number' => $tx->pra_invoice_number,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * List failed/offline PRA bills for the F11 header shortcut modal.
     * Returns bills with pra_status IN ('failed','offline','pending') that
     * have NOT yet received a pra_invoice_number (i.e. need cashier attention).
     */
    public function apiFailedBills(Request $request)
    {
        $companyId = app('currentCompanyId');

        // Billing Scope (07 Aug 2026): failed/offline/pending rows are PRA-pipeline
        // bills — a local-scoped account has no business seeing (or retrying) them.
        if ((auth('pos')->user()?->posBillingScope() ?? 'both') === 'local') {
            return response()->json(['success' => true, 'count' => 0, 'bills' => []]);
        }

        $bills = PosTransaction::where('company_id', $companyId)
            ->whereIn('pra_status', ['failed', 'offline', 'pending'])
            ->whereNull('pra_invoice_number')
            // Task 1197: isolated cashier's F11 queued/failed list = own bills.
            ->tap(fn ($q) => PosTransaction::applyCashierIsolation($q, auth('pos')->user()))
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get(['id', 'invoice_number', 'customer_name', 'total_amount', 'pra_status', 'pra_response_code', 'pra_error_message', 'created_at']);

        $data = $bills->map(function ($b) {
            return [
                'id'             => $b->id,
                'invoice_number' => $b->invoice_number,
                'customer_name'  => $b->customer_name,
                'total_amount'   => (float) $b->total_amount,
                'pra_status'     => $b->pra_status,
                'error_code'     => $b->pra_response_code,
                // Task 624: asal wajah (timeout / HTTP code / PRA message) — F11 modal.
                'error_message'  => $b->pra_error_message,
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

        // Billing Scope (07 Aug 2026): local-scoped staff never touch the PRA
        // pipeline. Task 1186: EXPLICIT scope only — retry is a write path.
        if (auth('pos')->user()?->posBillingScopeExplicit() === 'local') {
            return response()->json(['success' => false, 'message' => __('pos.billing_scope_local_only')], 403);
        }

        // ATOMIC CLAIM — race-safe. Conditional UPDATE returns affected-row
        // count; if 0, another concurrent request already claimed/submitted
        // this bill (double-click, two tabs, queue worker, etc.).
        $claimed = PosTransaction::where('company_id', $companyId)
            ->where('id', $id)
            ->whereNull('pra_invoice_number')
            ->whereIn('pra_status', ['pending', 'failed', 'offline'])
            // Task 1197: creator constraint INSIDE the atomic claim — an
            // isolated cashier can never claim a peer's failed bill, even
            // in a race (the UPDATE simply matches zero rows).
            ->tap(fn ($q) => PosTransaction::applyCashierIsolation($q, auth('pos')->user()))
            ->update(['pra_status' => 'pending', 'pra_response_code' => null]);

        if ($claimed === 0) {
            // Either bill doesn't exist, was already submitted, or another
            // request claimed it. Re-fetch to give the cashier the right reason.
            $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->first();
            if (!$tx) {
                return response()->json(['success' => false, 'message' => __('pos.bill_not_found')], 404);
            }
            // Task 1197: peer bill — explicit 403, not a misleading status hint.
            if (!$tx->allowedForCashierIsolationOf(auth('pos')->user())) {
                return response()->json(['success' => false, 'message' => __('pos.custom_access_denied')], 403);
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

    /**
     * ─── Task 705: khufia key (Ctrl+Alt+Shift+L) — manager/owner side ───────
     * Toggles the session-only "local check mode" flag: OFF (default) =
     * managers see ONLY the PRA stream and Z/X reports render PRA-only
     * figures; ON = local invoices + local-stream figures visible too.
     * Session-based — logout / fresh login clears it. No visible UI beyond a
     * tiny neutral dot (pos-app layout). VISIBILITY ONLY: what gets reported
     * to PRA never changes here (compliance boundary, Task 705).
     */
    public function toggleLocalCheck(Request $request)
    {
        $user = auth('pos')->user();
        // Manager/owner/admin only — cashiers & confined roles get a hard 403.
        if (!$user || !$user->isPosAdmin()) {
            abort(403);
        }
        $on = !session('pos_local_check');
        session(['pos_local_check' => $on]);

        return response()->json(['on' => $on]);
    }

    /**
     * ─── Task 705: khufia key — LOCAL cashier station identity switch ───────
     * Same key on a LOCAL-scoped cashier's station: the pos-guard session
     * flips to the owner-linked PRA counterpart cashier (no password) — sale
     * screen, today's bills, billing attribution sab us PRA ID par. Dobara
     * key = wapas asal local ID (original remembered in the session; login()
     * migrates the session but KEEPS data). Per-station only: doosre PCs ke
     * sessions untouched; both stations may bill the same PRA ID concurrently.
     * Unlinked/ineligible = silent no-op. Target can NEVER be a manager/
     * owner/admin (cashier-role filter) or another company's user.
     */
    public function identitySwitch(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user) {
            abort(403);
        }
        $companyId = app('currentCompanyId');
        $noop = response()->json(['switched' => false]);
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_counterpart_user_id')) {
                return $noop;
            }
        } catch (\Throwable $e) {
            return $noop;
        }

        // ── Switch BACK: original local ID remembered in this session ──
        $originalId = (int) session('pos_identity_original_id', 0);
        if ($originalId > 0) {
            $original = User::where('company_id', $companyId)
                ->where('id', $originalId)
                ->where('pos_role', 'pos_cashier')
                ->where('is_active', true)
                ->first();
            // Sanity: the CURRENT user must be the original's linked counterpart —
            // a stale/crafted session value must never become a free login.
            if (!$original || (int) ($original->pos_counterpart_user_id ?? 0) !== (int) $user->id) {
                session()->forget('pos_identity_original_id');

                return $noop;
            }
            $this->identitySwitchLogin($original);
            session()->forget('pos_identity_original_id');

            return response()->json(['switched' => true, 'direction' => 'back']);
        }

        // ── Switch FORWARD: a LOCAL-scoped cashier with an owner-set link ──
        if (!$user->isPosCashier()) {
            return $noop; // unlinked/ineligible = silent no-op (task rule)
        }
        // Task 1186: khufia switch is an EXPLICIT-lock feature (Task 705) —
        // the derived default must never activate/deactivate it.
        if ($user->posBillingScopeExplicit() === 'local') {
            if (!(int) ($user->pos_counterpart_user_id ?? 0)) {
                return $noop;
            }
            $target = User::where('company_id', $companyId)
                ->where('id', (int) $user->pos_counterpart_user_id)
                ->where('pos_role', 'pos_cashier') // NEVER manager/owner/admin
                ->where('is_active', true)
                ->first();
            if (!$target || $target->posBillingScopeExplicit() === 'local') {
                return $noop;
            }
            session(['pos_identity_original_id' => $user->id]);
            $this->identitySwitchLogin($target);

            return response()->json(['switched' => true, 'direction' => 'pra']);
        }

        // ── Switch REVERSE (owner test, 18 Aug 2026): the key must work from
        // BOTH sides. A fresh login on the PRA-side ID (no session memory)
        // flips to the LOCAL cashier that points at it — but ONLY when exactly
        // one active local cashier is linked (two stations sharing one PRA ID
        // = ambiguous, silent no-op; the switched-forward session still goes
        // back via the original-id branch above). Same trust boundary as
        // forward: owner-set link, cashier roles only, same company.
        $locals = User::where('company_id', $companyId)
            ->where('pos_counterpart_user_id', $user->id)
            ->where('pos_role', 'pos_cashier')
            ->where('is_active', true)
            ->get()
            ->filter(fn ($u) => $u->posBillingScopeExplicit() === 'local')
            ->values();
        if ($locals->count() !== 1) {
            return $noop;
        }
        $this->identitySwitchLogin($locals->first());

        return response()->json(['switched' => true, 'direction' => 'local']);
    }

    /**
     * Task 705: re-login the pos guard as $target WITHOUT a Staff Hazri row —
     * an identity switch is not a fresh staff arrival. The AppServiceProvider
     * Login listener skips its pos_user_sessions insert when this request
     * attribute is set (hazri double-count guard). Session data (the
     * original-id memory) survives login()'s session migrate.
     */
    private function identitySwitchLogin(User $target): void
    {
        request()->attributes->set('pos_identity_switch', true);
        \Illuminate\Support\Facades\Auth::guard('pos')->login($target);
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
        // Exempt tab (Task 647): all-exempt bills (never reported to PRA) —
        // visible to EVERY role and BOTH billing scopes (they belong to no stream).
        if ($request->get('tab') === 'exempt') {
            $tab = 'exempt';
        }
        // Billing Scope (07 Aug 2026): scope-locked staff are FORCED onto their
        // own stream's tab — a local-scoped manager/cashier IS the offline-billing
        // admin (overrides the admin-only Local rule), a pra-scoped one never
        // sees the Local tab. The other tab is hidden in the view too.
        $scope = $user?->posBillingScope() ?? 'both';
        if ($scope !== 'both' && $tab !== 'exempt') {
            $tab = $scope === 'local' ? 'local' : 'pra';
        }
        // Task 705: manager default PRA-only — bina khufia local-check mode ke
        // pos_manager ka Local tab server-side band (view mein tab chhupta bhi hai).
        if ($tab === 'local' && ($user?->posHidesLocalStream() ?? false)) {
            $tab = 'pra';
        }

        $query = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->with(['creator', 'restaurantOrder.table.floor']);

        // Return-button eligibility (Task 678): remaining returnable quantity
        // per row, aggregated in the SAME page query (no N+1) — a fully
        // returned bill hides its Return action.
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'returned_quantity')) {
            $query->withSum('items as items_qty_total', 'quantity')
                ->withSum('items as items_returned_total', 'returned_quantity');
        }

        // Task 1197 — per-cashier sales isolation + admin per-cashier view:
        // an ISOLATED cashier (company switch, default ON) is FORCED onto
        // their own bills; admin/manager may inspect any one team member via
        // ?cashier=ID (dropdown mirrors the Reports filter). Composes with
        // the billing-scope/tab predicates (AND, never replace).
        $txnIsolated = (bool) ($user?->posSalesIsolated() ?? false);
        $cashierFilter = 'all';
        if ($txnIsolated) {
            $cashierFilter = (string) $user->id;
        } elseif (($user?->isPosAdmin() ?? false) && $request->filled('cashier') && $request->get('cashier') !== 'all') {
            $cashierFilter = (string) (int) $request->get('cashier');
        }

        // Task 1186: derived-scope viewers get their own cross-stream rows
        // unioned into their forced tab (own-bill exemption on the list).
        $this->applyReportFilters($query, $tab, $cashierFilter, $user);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $like = \App\Helpers\DbCompat::like();
                $q->where('invoice_number', $like, "%{$search}%")
                    ->orWhere('customer_name', $like, "%{$search}%");
            });
        }

        if ($request->filled('payment_method')) {
            $method = $request->payment_method;
            // Legacy rows carry 'card' (before the write-path was normalised to
            // 'debit_card'). Both values mean the same thing to the shopkeeper,
            // so selecting Debit Card must surface both. Use the canonical alias
            // set from PosPaymentBuckets as the single source of truth.
            if (in_array($method, \App\Support\PosPaymentLabels::CARD_ALIASES, true)) {
                $query->whereIn('payment_method', \App\Support\PosPaymentLabels::CARD_ALIASES);
            } else {
                $query->where('payment_method', $method);
            }
        }

        // Order type filter (Task 977): dine_in / takeaway / delivery —
        // only meaningful for restaurant-mode companies; plain retail bills
        // have NULL order_type and won't appear when a specific type is selected.
        $validOrderTypes = ['dine_in', 'takeaway', 'delivery'];
        if ($request->filled('order_type') && in_array($request->order_type, $validOrderTypes, true)) {
            $query->where('order_type', $request->order_type);
        }

        // Wastage filter (Task 593): only wastage-flagged return bills —
        // spoiled goods whose stock was NOT restored. Schema-drift guarded.
        if ($request->boolean('wastage') && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_wastage')) {
            $query->where('transaction_type', 'return')->where('is_wastage', true);
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

        // Task 1197: cashier-filter dropdown data (admin/manager only —
        // mirrors reports()). Cashiers never get the list.
        $teamMembers = ($user?->isPosAdmin() ?? false)
            ? User::where('company_id', $companyId)
                ->whereNotNull('pos_role')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'pos_role'])
            : collect();
        $selectedCashier = $cashierFilter;

        return view('pos.transactions', compact('transactions', 'tab', 'hasPinSet', 'localCount', 'user', 'company', 'teamMembers', 'selectedCashier', 'txnIsolated'));
    }

    /**
     * Billing Scope (07 Aug 2026) — stream-lock READ guard: may this user see
     * this bill? Local stream mirrors applyReportFilters (L-series bills OR
     * reporting-OFF finals: NULL pra_status + no fiscal number); everything
     * else is the PRA stream. 'both' (default/admin) sees everything.
     */
    private function billingScopeAllowsRow(PosTransaction $txn): bool
    {
        // Single predicate (Task 647): exempt bills are visible to EVERY scope.
        // Task 1186: effective scope + own-bill exemption (derived default only)
        // — a viewer's own bill is always readable/printable.
        // Task 1197: AND the per-cashier isolation verdict — an isolated
        // cashier gets 403 on another cashier's bill even via direct link
        // (detail page, receipt reprint, PDF download).
        $rowUser = auth('pos')->user();

        return $txn->allowedForBillingScopeOf($rowUser)
            && $txn->allowedForCashierIsolationOf($rowUser);
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
                if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_archived')) {
                    $q->where('is_archived', false)
                      ->orWhereNull('is_archived')
                      ->orWhere('invoice_mode', 'local');
                }
            })
            ->with(['items', 'payments', 'praLogs', 'creator', 'terminal', 'restaurantOrder.table.floor', 'deliveredBy'])
            ->findOrFail($id);

        // Billing Scope: stream-locked staff cannot open the other stream's bills.
        abort_unless($this->billingScopeAllowsRow($transaction), 403);

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
                if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_archived')) {
                    $q->where('is_archived', false)
                      ->orWhereNull('is_archived')
                      ->orWhere('invoice_mode', 'local');
                }
            })
            ->with(['items', 'payments', 'creator', 'terminal', 'rider'])
            ->findOrFail($id);

        // Billing Scope: stream-locked staff cannot reprint the other stream's bills.
        abort_unless($this->billingScopeAllowsRow($transaction), 403);

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
        // Task 816: split-payment breakdown section (heading + one row per bucket).
        if ($transaction->relationLoaded('payments') && $transaction->payments->count() >= 2) {
            $height += 30.0 + ($transaction->payments->count() * 22.0);
        }
        // Notes wrap to several lines on a narrow thermal roll — scale by length, never assume one line.
        if (!empty($transaction->notes)) {
            $noteCharsPerLine = $printerSize === '58mm' ? 28 : 40;
            $noteLines = max(1, (int) ceil(mb_strlen((string) $transaction->notes) / $noteCharsPerLine));
            $height += 20.0 + ($noteLines * 14.0);
        }
        $height += 250.0;                                          // PRA/provisional badge + 100px QR + caption
        $height += 80.0;                                           // safety tail so nothing clips

        // Task 1287: Urdu-script receipts render Jameel Noori Nastaleeq at
        // line-height 1.9 (vs 1.6 Naskh baseline this estimate was tuned for)
        // — every text row runs taller. Only the mPDF path can still see 'ur'
        // here: DomPDF callers run applyPdfSafeLocale() (ur → rur) BEFORE
        // calling this, so the multiplier never inflates DomPDF heights.
        if (app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT) {
            $height *= 1.25;
        }

        return max(640.0, $height);
    }

    public function downloadInvoicePdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with(['items', 'payments', 'terminal', 'creator', 'rider'])
            ->findOrFail($id);

        // Billing Scope: stream-locked staff cannot download the other stream's bills.
        abort_unless($this->billingScopeAllowsRow($transaction), 403);

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

        // Task 1197: an isolated cashier can't mint a share link (WhatsApp
        // Bill) for another cashier's bill — read-guard parity with the
        // receipt/PDF paths.
        if (!$transaction->allowedForCashierIsolationOf(auth('pos')->user())) {
            return response()->json([
                'success' => false,
                'message' => __('pos.wa_share_not_allowed'),
            ], 403);
        }

        // Task 1036 (review-locked): public receipt links are FINAL-bill only.
        // A deliberate provisional (pra_status='local') is still editable /
        // deletable — never mint a public token for it, even for an
        // authenticated same-company POST. The company WhatsApp-Bill toggle
        // also gates minting; missing column fails OPEN (feature default is
        // ON — prod schema drift must not kill the pre-existing Share button).
        $company = Company::find($companyId);
        $shareOn = (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_whatsapp_bill_enabled')
                || (bool) ($company?->pos_whatsapp_bill_enabled ?? true))
            && \App\Services\PosFeatureService::planAllows($company, 'whatsapp_enabled');
        if (!$shareOn || $transaction->isDeliberateProvisional()) {
            return response()->json([
                'success' => false,
                'message' => __('pos.wa_share_not_allowed'),
            ], 422);
        }

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
            ->with(['items', 'payments', 'terminal', 'creator', 'rider'])
            ->firstOrFail();

        if ($transaction->share_token_created_at && $transaction->share_token_created_at < now()->subDays(30)) {
            abort(410, 'This share link has expired.');
        }

        // Task 1036 (review-locked): a deliberate provisional is still editable —
        // even a legacy/pre-hardening token for one must never render publicly.
        // (Promotion clears pra_status='local', so the same token starts working
        // the moment the bill becomes final.) Direct response, NOT abort(404):
        // the global NotFound renderable would 302 a pos/* path to /pos/login,
        // which is wrong for a public customer-facing link.
        if ($transaction->isDeliberateProvisional()) {
            return response('This share link is no longer available.', 404);
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

    private function applyReportFilters($query, $tab, $cashierFilter = null, $forUser = null)
    {
        // Multi-branch v1 (Task 1347): SINGLE choke point for branch scoping —
        // transactions list, sales reports, tax reports, exports and the
        // analytics PDF all funnel through here. Applied BEFORE the derived-scope
        // early return below so no caller can slip past it. No-op when the
        // company has no branches or the owner picked "all branches"; legacy
        // rows (branch_id NULL) always stay visible.
        app(\App\Services\BranchContextService::class)->applyToQuery($query, 'branch_id');

        // Task 1186 own-bill union: when the viewer is on the DERIVED default
        // scope, their own cross-stream rows join their forced tab
        // ((stream) OR created_by = viewer) so an own F10 provisional never
        // vanishes from Transactions/Reports — it stays findable, reprintable
        // and returnable. Explicit (owner-locked) scopes stay strict, and the
        // TAX reports never pass $forUser (legal PRA figures stay stream-pure).
        // method_exists guard: analytics callers may pass a lightweight stub
        // user (tests / internal builders) — treat it as non-derived.
        if ($forUser && in_array($tab, ['pra', 'local'], true)
            && method_exists($forUser, 'posBillingScopeIsDerived')
            && $forUser->posBillingScopeIsDerived()) {
            if ($tab === 'local') {
                // Mirror applyStreamTab's local-tab archive bypass — must run on
                // the OUTER query (global scopes ignore nested-closure calls).
                $query->withoutGlobalScope('hide_archived');
            }
            $query->where(function ($outer) use ($tab, $forUser) {
                $outer->where(fn ($s) => PosTransaction::applyStreamTab($s, $tab))
                    ->orWhere('created_by', $forUser->id);
            });

            if ($cashierFilter && $cashierFilter !== 'all') {
                $query->where('created_by', $cashierFilter);
            }

            return $query;
        }
        // Two FULLY ISOLATED report sets (owner rule Jul 2026) — split by whether the
        // bill was actually REPORTED to PRA (fiscal), not just by invoice_mode:
        //   tab='pra'   → bills in the PRA pipeline: pra_status NOT NULL (pending /
        //                 completed / failed / offline) OR a PRA fiscal number exists.
        //   tab='local' → bills PRA never saw: L-series (invoice_mode='local') PLUS
        //                 reporting-OFF finals (mode pra/NULL + pra_status NULL + no
        //                 fiscal number — "jis pe PRA fiscal nahi aya wo local hai").
        //                 INCLUDING archived ones, so hide_archived is bypassed.
        //                 Admin-only (callers force 'pra' for cashiers).
        //   tab='exempt' → all-exempt bills (pra_status='exempt_internal') — PRA
        //                 never sees them by design (Task 647). Excluded from the
        //                 PRA tab; own tab, visible to both billing scopes.
        // SINGLE predicate — PosTransaction::applyStreamTab is the only truth.
        PosTransaction::applyStreamTab($query, $tab);

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
        // Exempt tab (Task 647): every role + both scopes may view it.
        if ($request->get('tab') === 'exempt') {
            $tab = 'exempt';
        }
        // Billing Scope (07 Aug 2026): scope-locked staff report ONLY their own
        // stream — mirrors the Transactions tab forcing.
        $reportScope = $user?->posBillingScope() ?? 'both';
        if ($reportScope !== 'both' && $tab !== 'exempt') {
            $tab = $reportScope === 'local' ? 'local' : 'pra';
        }
        // Task 705: manager default PRA-only (khufia local-check mode OFF).
        if ($tab === 'local' && ($user?->posHidesLocalStream() ?? false)) {
            $tab = 'pra';
        }
        $cashierFilter = $request->get('cashier', 'all');

        // Owner rule (5 Aug 2026): a cashier ALWAYS sees only their own sales
        // in reports — 'all' and other members' ids are force-overridden.
        if ($isCashier) {
            $cashierFilter = (string) $user->id;
        }

        $teamMembers = User::where('company_id', $companyId)
            ->whereNotNull('pos_role')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'pos_role']);

        $modeFilter = function ($q) use ($tab, $cashierFilter, $user) {
            // Task 1186: derived viewers see their own cross-stream rows too
            // (cashier reports are already forced to created_by = self, so the
            // union means "ALL apni sales", both streams).
            $this->applyReportFilters($q, $tab, $cashierFilter, $user);
        };

        // Return / credit-note netting (Task 570): revenue figures are SIGNED
        // (returns subtract), bill counts stay sales-only. Schema-guarded for
        // prod drift — pre-migration boxes fall back to the old unsigned sums.
        $typeReady = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type');
        $signExpr = $typeReady ? "CASE WHEN transaction_type = 'return' THEN -1 ELSE 1 END" : '1';
        $saleRowExpr = $typeReady ? "CASE WHEN transaction_type = 'return' THEN 0 ELSE 1 END" : '1';

        // Sales reports group by BUSINESS day (owner rule 26 Jul 2026): an
        // after-midnight bill counts toward the previous day's business.
        // Tax reports (buildTaxReportQuery) stay on created_at — PRA legal truth.
        $dailySales = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('business_date', '>=', now()->subDays(30)->toDateString())
            ->tap($modeFilter)
            ->selectRaw("business_date as date, COALESCE(SUM({$saleRowExpr}),0) as count, COALESCE(SUM(({$signExpr}) * total_amount),0) as revenue")
            ->groupBy('business_date')
            ->orderBy('date', 'desc')
            ->get();

        $paymentSummary = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('business_date', '>=', now()->startOfMonth()->toDateString())
            ->tap($modeFilter)
            ->selectRaw("payment_method, COALESCE(SUM({$saleRowExpr}),0) as count, COALESCE(SUM(({$signExpr}) * total_amount),0) as total, COALESCE(SUM(({$signExpr}) * tax_amount),0) as tax")
            ->groupBy('payment_method')
            ->get();

        $topItems = PosTransactionItem::whereHas('transaction', function ($q) use ($companyId, $tab, $cashierFilter, $typeReady, $user) {
            $q->where('company_id', $companyId)->where('status', 'completed')->where('business_date', '>=', now()->startOfMonth()->toDateString());
            // Top-seller ranking stays GROSS sales — return rows (RET- lines)
            // are excluded rather than netted so rankings don't go negative.
            if ($typeReady) {
                $q->where(function ($w) {
                    $w->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
                });
            }
            $this->applyReportFilters($q, $tab, $cashierFilter, $user);
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
            ->selectRaw(\App\Helpers\DbCompat::dateFormat('business_date', 'YYYY-MM') . " as month, COALESCE(SUM({$saleRowExpr}),0) as count, COALESCE(SUM(({$signExpr}) * total_amount),0) as revenue")
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
                // Same non-reported set as applyReportFilters — SINGLE predicate
                // (Task 647): exempt_internal is excluded regardless of mode.
                ->tap(fn ($q) => PosTransaction::applyStreamTab($q, 'local'))
                ->when($cashierFilter && $cashierFilter !== 'all', fn ($q) => $q->where('created_by', $cashierFilter))
                ->with('creator')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString();
        }

        // ── Range analytics (owner request Jul 2026): date-window deep dive ──
        // Plan gate (Task 664 review): the analytics deep dive is a paid
        // entitlement (analytics_enabled, Business+ since Aug 2026). Ineligible
        // plans get NULL — the data is never built for them; the view renders
        // an upgrade-locked card instead. The PDF endpoint has its own gate.
        $rangeAnalytics = null;
        if (PosFeatureService::planAllows($company, 'analytics_enabled')) {
            [$rangeFrom, $rangeTo] = $this->resolveReportRange($request);
            $rangeAnalytics = $this->buildReportRangeAnalytics($companyId, $rangeFrom, $rangeTo, $tab, $cashierFilter, $company, $user);
        }

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
        // Task 705: manager PRA-only default — Local export needs local-check mode ON.
        $tab = ($request->get('tab') === 'local' && $user?->isPosAdmin() && !$user->posHidesLocalStream()) ? 'local' : 'pra';
        if ($request->get('tab') === 'exempt') {
            $tab = 'exempt'; // Task 647: exempt stream, open to every role/scope
        }
        $cashierFilter = $request->get('cashier', 'all');
        // Owner rule (5 Aug 2026): cashier reports are locked to OWN sales.
        if ($isCashier) {
            $cashierFilter = (string) $user->id;
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
        // Task 705: manager PRA-only default — Local export needs local-check mode ON.
        $tab = ($request->get('tab') === 'local' && $user?->isPosAdmin() && !$user->posHidesLocalStream()) ? 'local' : 'pra';
        if ($request->get('tab') === 'exempt') {
            $tab = 'exempt'; // Task 647: exempt stream, open to every role/scope
        }
        $cashierFilter = $request->get('cashier', 'all');

        // Owner rule (5 Aug 2026): a cashier ALWAYS sees only their own sales
        // in reports — 'all' and other members' ids are force-overridden.
        if ($isCashier) {
            $cashierFilter = (string) $user->id;
        }

        // Custom date range (analytics block) wins; default stays last 30 days.
        $hasRange = $request->filled('from') || $request->filled('to');
        [$rangeFrom, $rangeTo] = $this->resolveReportRange($request);

        $transactions = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->when($hasRange,
                fn ($q) => $q->whereBetween('business_date', [$rangeFrom->toDateString(), $rangeTo->toDateString()]),
                fn ($q) => $q->where('business_date', '>=', now()->subDays(30)->toDateString()))
            // Task 1186: derived viewers export their own cross-stream rows too.
            ->tap(fn ($q) => $this->applyReportFilters($q, $tab, null, $user))
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
                    \App\Support\PosPaymentLabels::label($t->payment_method),
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

        // Bill-type filter (Task 695): all / sales-only / credit-notes-only.
        // Return rows are stored POSITIVE with transaction_type='return' —
        // schema-guarded so pre-migration boxes silently ignore the filter.
        if ($request->filled('bill_type')
            && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type')) {
            if ($request->bill_type === 'sales') {
                $query->where(function ($w) {
                    $w->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
                });
            } elseif ($request->bill_type === 'returns') {
                $query->where('transaction_type', 'return');
            }
        }

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
            $method = $request->payment_method;
            // Legacy rows carry 'card' (pre-normalisation alias for 'debit_card').
            // Surface both when the user selects Debit Card.
            if (in_array($method, \App\Support\PosPaymentLabels::CARD_ALIASES, true)) {
                $query->whereIn('payment_method', \App\Support\PosPaymentLabels::CARD_ALIASES);
            } else {
                $query->where('payment_method', $method);
            }
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
        } else {
            // Owner (26 Aug 2026): Tax Reports used to open on ALL TIME, so a
            // credit note from months ago read as if it belonged to today and
            // the shop could not reconcile it against the day's bills. The
            // screen now opens on TODAY; All Time stays available as a
            // deliberate choice (period=all — and the legacy EMPTY period that
            // old links, bookmarks and export URLs still carry keeps meaning
            // all time, so nothing silently narrows behind the user's back).
            switch ($this->reportPeriod($request)) {
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

    /**
     * Period actually in force on the tax report.
     *
     * Absent parameter (a fresh visit) = TODAY. An explicitly empty period is
     * the legacy "All Time" spelling and keeps that meaning, as does the new
     * explicit 'all'. Date range, when present, wins over this everywhere.
     */
    private function reportPeriod(Request $request): string
    {
        if (!$request->has('period')) {
            return 'today';
        }

        $period = trim((string) $request->query('period'));

        return $period === '' ? 'all' : $period;
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

        // The header must name the SAME period the query used (owner, 26 Aug
        // 2026) — a fresh visit now means today, so "All Time" here would have
        // described a day's figures as the shop's whole history.
        return match ($this->reportPeriod($request)) {
            'today' => 'Today (' . now()->format('d M Y') . ')',
            'yesterday' => 'Yesterday (' . now()->subDay()->format('d M Y') . ')',
            'weekly' => 'This Week (' . now()->startOfWeek()->format('d M') . ' - ' . now()->endOfWeek()->format('d M Y') . ')',
            'monthly' => 'This Month (' . now()->format('M Y') . ')',
            'last_month' => 'Last Month (' . now()->subMonth()->format('M Y') . ')',
            default => 'All Time',
        };
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

    /**
     * Credit-note netting expressions for the tax report (Task 695 — same
     * convention as the sales dashboard / Task 570): money sums multiply by
     * the sign expression (returns subtract, amounts are stored POSITIVE on
     * return rows); invoice counts count SALE rows only. In credit-notes-only
     * mode ($returnsOnly) figures stay POSITIVE — the view labels them as
     * refunded amounts. Schema-guarded for prod drift.
     *
     * @return array{0: bool, 1: bool, 2: string, 3: string, 4: string}
     *         [$typeReady, $returnsOnly, $signExpr, $saleRowExpr, $isReturnExpr]
     */
    private function taxReportNettingExprs(Request $request): array
    {
        $typeReady = false;
        try {
            $typeReady = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type');
        } catch (\Throwable $e) {
            $typeReady = false;
        }
        $returnsOnly = $typeReady && $request->get('bill_type') === 'returns';

        $sign = ($typeReady && !$returnsOnly)
            ? "CASE WHEN pos_transactions.transaction_type = 'return' THEN -1 ELSE 1 END"
            : '1';
        $saleRow = ($typeReady && !$returnsOnly)
            ? "CASE WHEN pos_transactions.transaction_type = 'return' THEN 0 ELSE 1 END"
            : '1';
        $isReturn = $typeReady
            ? "CASE WHEN pos_transactions.transaction_type = 'return' THEN 1 ELSE 0 END"
            : '0';

        return [$typeReady, $returnsOnly, $sign, $saleRow, $isReturn];
    }

    private function buildItemLevelSummary($transactionIds, $taxRateFilter, bool $typeReady = false, bool $returnsOnly = false)
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

        // Credit-note netting (Task 695): return items join through their
        // return transaction — money sums are signed (returns subtract),
        // invoice counts are sale bills only. Credit-notes-only mode keeps
        // POSITIVE refunded figures.
        $signed = $typeReady && !$returnsOnly;
        $sign = $signed ? "CASE WHEN pos_transactions.transaction_type = 'return' THEN -1 ELSE 1 END" : '1';
        $isReturn = $typeReady ? "CASE WHEN pos_transactions.transaction_type = 'return' THEN 1 ELSE 0 END" : '0';
        $invoiceExpr = $signed
            ? "COUNT(DISTINCT CASE WHEN COALESCE(pos_transactions.transaction_type, 'sale') != 'return' THEN pos_transaction_items.transaction_id END)"
            : "COUNT(DISTINCT pos_transaction_items.transaction_id)";

        $hasThirdCol = \Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'is_third_schedule');
        $thirdExpr = $hasThirdCol
            ? "COALESCE(SUM(CASE WHEN pos_transaction_items.is_third_schedule = 1 OR pos_transaction_items.is_third_schedule = true THEN ({$sign}) * ({$base}) ELSE 0 END), 0) as total_third_schedule,"
            : "0 as total_third_schedule,";

        return $itemQuery->selectRaw("
            {$invoiceExpr} as total_invoices,
            COALESCE(SUM(({$sign}) * ({$base})), 0) as total_sales,
            COALESCE(SUM(({$sign}) * pos_transaction_items.tax_amount), 0) as total_tax,
            COALESCE(SUM(CASE WHEN pos_transaction_items.is_tax_exempt = 1 OR pos_transaction_items.is_tax_exempt = true THEN ({$sign}) * ({$base}) ELSE 0 END), 0) as total_exempt,
            COALESCE(SUM(CASE WHEN pos_transaction_items.is_tax_exempt = 0 OR pos_transaction_items.is_tax_exempt = false THEN ({$sign}) * ({$base}) ELSE 0 END), 0) as total_taxable,
            {$thirdExpr}
            COUNT(DISTINCT CASE WHEN ({$isReturn}) = 1 THEN pos_transaction_items.transaction_id END) as return_count,
            COALESCE(SUM(({$isReturn}) * (({$base}) + pos_transaction_items.tax_amount)), 0) as return_amount,
            COALESCE(SUM(({$isReturn}) * pos_transaction_items.tax_amount), 0) as return_tax
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
        // Task 705: manager PRA-only default — Local tab needs local-check mode ON.
        $tab = ($request->get('tab') === 'local' && auth('pos')->user()?->isPosAdmin()
            && !(auth('pos')->user()?->posHidesLocalStream() ?? false)) ? 'local' : 'pra';

        $taxRateFilter = $request->filled('tax_rate') ? $request->tax_rate : null;
        [$typeReady, $returnsOnly, $signExpr, $saleRowExpr, $isReturnExpr] = $this->taxReportNettingExprs($request);
        $billTypeFilter = $typeReady && in_array($request->get('bill_type'), ['sales', 'returns'], true)
            ? $request->get('bill_type') : '';

        $baseQuery = $this->buildTaxReportQuery($request, $tab, true);
        $transactions = $baseQuery->paginate(50)->appends($request->all());

        $itemValues = [];
        if ($taxRateFilter) {
            $allIdsQuery = $this->buildTaxReportQuery($request, $tab, true);
            $allIds = $allIdsQuery->pluck('id')->toArray();

            $summary = $this->buildItemLevelSummary($allIds, $taxRateFilter, $typeReady, $returnsOnly);
            $summary->total_discount = 0;
            $summary->total_third_schedule = $summary->total_third_schedule ?? 0;

            $itemValues = $this->getItemLevelValuesForTransactions($transactions, $taxRateFilter);
        } else {
            $summaryQuery = $this->buildTaxReportQuery($request, $tab, true);
            // Credit-note netting (Task 695): money sums are SIGNED (returns
            // subtract), invoice count = sale bills only; the credit-note
            // count/amount ride alongside so nothing is hidden. In
            // credit-notes-only mode figures stay POSITIVE (refunded).
            $summary = $summaryQuery->reorder()->selectRaw("
                COALESCE(SUM({$saleRowExpr}), 0) as total_invoices,
                COALESCE(SUM(({$signExpr}) * total_amount), 0) as total_sales,
                COALESCE(SUM(({$signExpr}) * discount_amount), 0) as total_discount,
                COALESCE(SUM(({$signExpr}) * (subtotal - discount_amount - COALESCE(exempt_amount, 0))), 0) as total_taxable,
                COALESCE(SUM(({$signExpr}) * tax_amount), 0) as total_tax,
                COALESCE(SUM(({$signExpr}) * COALESCE(exempt_amount, 0)), 0) as total_exempt,
                COALESCE(SUM({$isReturnExpr}), 0) as return_count,
                COALESCE(SUM(({$isReturnExpr}) * total_amount), 0) as return_amount,
                COALESCE(SUM(({$isReturnExpr}) * tax_amount), 0) as return_tax
            ")->first();

            // Third Schedule breakdown (Aug 2026): item-level query so we can
            // split exempt into Third Schedule vs regular exempt for monthly return.
            $thirdScheduleTotal = 0;
            if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'is_third_schedule')) {
                $allIds = $this->buildTaxReportQuery($request, $tab, true)->pluck('id')->toArray();
                if (!empty($allIds)) {
                    $base = $this->itemBaseSqlExpr();
                    $thirdScheduleTotal = (float) (\App\Models\PosTransactionItem::query()
                        ->join('pos_transactions', 'pos_transactions.id', '=', 'pos_transaction_items.transaction_id')
                        ->whereIn('pos_transaction_items.transaction_id', $allIds)
                        ->where('pos_transaction_items.is_third_schedule', true)
                        ->selectRaw("COALESCE(SUM(({$signExpr}) * ({$base})), 0) as ts_total")
                        ->value('ts_total') ?? 0);
                }
            }
            $summary->total_third_schedule = $thirdScheduleTotal;
            // Exempt (other) = exempt_amount minus Third Schedule portion
            $summary->total_exempt_other = max(0, (float) ($summary->total_exempt ?? 0) - $thirdScheduleTotal);
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
        $billTypeReady = $typeReady;

        // Credit-note traceability (owner, 26 Aug 2026): the summary line used to
        // announce "Credit Notes: 1 — Refunded 750" with no way to tell WHICH
        // bill or WHEN, so the shop could not match it against the day's sales
        // (and read it as a cancellation that the cancelled-orders page denied).
        // The line now names the bills themselves; the table already lists them
        // in full under Bill Type = Credit Notes Only.
        $creditNoteBills = collect();
        if ($typeReady && (int) ($summary->return_count ?? 0) > 0 && $billTypeFilter !== 'returns') {
            $creditNoteBills = $this->buildTaxReportQuery($request, $tab, true)
                ->where('transaction_type', 'return')
                ->reorder('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'invoice_number', 'created_at', 'total_amount']);
        }

        return view('pos.tax-reports', compact('company', 'transactions', 'summary', 'dateLabel', 'taxRateLabel', 'tab', 'hasPinSet', 'localCount', 'user', 'itemValues', 'taxRateFilter', 'availableRates', 'billTypeFilter', 'billTypeReady', 'creditNoteBills'));
    }

    public function exportTaxReportCsv(Request $request)
    {
        if ($r = $this->planGate('reports_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Local export is ADMIN-ONLY — cashiers always export PRA data.
        // Task 705: manager PRA-only default — Local export needs local-check mode ON.
        $tab = ($request->get('tab') === 'local' && auth('pos')->user()?->isPosAdmin()
            && !(auth('pos')->user()?->posHidesLocalStream() ?? false)) ? 'local' : 'pra';

        $taxRateFilter = $request->filled('tax_rate') ? $request->tax_rate : null;
        // Credit-note netting (Task 695): CSV honors the bill-type filter and
        // uses the same SIGNED math as the screen — returns subtract, unless
        // the credit-notes-only view is active (positive refunded figures).
        [$typeReady, $returnsOnly, $signExpr, $saleRowExpr, $isReturnExpr] = $this->taxReportNettingExprs($request);
        $rowIsReturn = fn ($t) => $typeReady && ($t->transaction_type ?? 'sale') === 'return';
        $rowSign = fn ($t) => ($rowIsReturn($t) && !$returnsOnly) ? -1 : 1;

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

        $callback = function () use ($transactions, $taxRateFilter, $itemValues, $taxRateLabel, $typeReady, $returnsOnly, $signExpr, $rowIsReturn, $rowSign) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            if ($taxRateFilter) {
                fputcsv($file, [
                    'POS Invoice Number',
                    'PRA Fiscal Invoice Number',
                    'Invoice Date',
                    'Customer Name',
                    'Bill Type',
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
                $returnCount = 0;
                $returnRefunded = 0;

                foreach ($transactions as $t) {
                    $iv = $itemValues[$t->id] ?? null;
                    if (!$iv) continue;

                    $sign = $rowSign($t);
                    $itemSub = $sign * (float)($iv['item_subtotal'] ?? 0);
                    $itemTax = $sign * (float)($iv['item_tax'] ?? 0);
                    $itemTotal = $itemSub + $itemTax;

                    $totalValue += $itemSub;
                    $totalTax += $itemTax;
                    $totalWithTax += $itemTotal;
                    if ($rowIsReturn($t)) {
                        $returnCount++;
                        $returnRefunded += (float)($iv['item_subtotal'] ?? 0) + (float)($iv['item_tax'] ?? 0);
                    }

                    fputcsv($file, [
                        $t->invoice_number,
                        $t->pra_invoice_number ?? 'N/A',
                        $t->created_at->format('d/m/Y H:i'),
                        $t->customer_name ?? 'Walk-in',
                        $rowIsReturn($t) ? 'Credit Note' : 'Sale',
                        \App\Support\PosPaymentLabels::label($t->payment_method),
                        number_format($itemSub, 2, '.', ''),
                        number_format($itemTax, 2, '.', ''),
                        number_format($itemTotal, 2, '.', ''),
                        $t->terminal?->terminal_name ?? 'N/A',
                        strtoupper($t->pra_status ?? 'N/A'),
                    ]);
                }

                fputcsv($file, []);
                fputcsv($file, ['SUMMARY — ' . $taxRateLabel . ($returnsOnly ? ' (Credit Notes Only — refunded amounts)' : '')]);
                fputcsv($file, ['Invoices with ' . $taxRateLabel . ' items', count(array_filter($itemValues, fn($v) => ($v['item_subtotal'] ?? 0) > 0))]);
                fputcsv($file, [$taxRateLabel . ' Value (PKR)', number_format($totalValue, 2, '.', '')]);
                fputcsv($file, [$taxRateLabel . ' Tax Amount (PKR)', number_format($totalTax, 2, '.', '')]);
                fputcsv($file, [$taxRateLabel . ' Total (PKR)', number_format($totalWithTax, 2, '.', '')]);
                if ($typeReady) {
                    fputcsv($file, ['Credit Notes (count)', $returnCount]);
                    fputcsv($file, ['Credit Notes Refunded (PKR)', number_format($returnRefunded, 2, '.', '')]);
                }
            } else {
                fputcsv($file, [
                    'POS Invoice Number',
                    'PRA Fiscal Invoice Number',
                    'Invoice Date',
                    'Customer Name',
                    'Bill Type',
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
                    $sign = $rowSign($t);
                    fputcsv($file, [
                        $t->invoice_number,
                        $t->pra_invoice_number ?? 'N/A',
                        $t->created_at->format('d/m/Y H:i'),
                        $t->customer_name ?? 'Walk-in',
                        $rowIsReturn($t) ? 'Credit Note' : 'Sale',
                        \App\Support\PosPaymentLabels::label($t->payment_method),
                        number_format($sign * $t->subtotal, 2, '.', ''),
                        number_format($sign * $t->discount_amount, 2, '.', ''),
                        number_format($sign * ($t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0)), 2, '.', ''),
                        number_format($sign * ($t->exempt_amount ?? 0), 2, '.', ''),
                        number_format($t->tax_rate, 2, '.', ''),
                        number_format($sign * $t->tax_amount, 2, '.', ''),
                        number_format($sign * $t->total_amount, 2, '.', ''),
                        $t->terminal?->terminal_name ?? 'N/A',
                        strtoupper($t->pra_status ?? 'N/A'),
                    ]);
                }

                fputcsv($file, []);
                // Third Schedule breakdown for CSV summary — SIGNED (returns subtract)
                $csvThirdTotal = 0;
                if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'is_third_schedule')) {
                    $csvAllIds = $transactions->pluck('id')->toArray();
                    if (!empty($csvAllIds)) {
                        $base2 = $this->itemBaseSqlExpr();
                        $csvThirdTotal = (float) (\App\Models\PosTransactionItem::query()
                            ->join('pos_transactions', 'pos_transactions.id', '=', 'pos_transaction_items.transaction_id')
                            ->whereIn('pos_transaction_items.transaction_id', $csvAllIds)
                            ->where('pos_transaction_items.is_third_schedule', true)
                            ->selectRaw("COALESCE(SUM(({$signExpr}) * ({$base2})), 0) as ts_total")
                            ->value('ts_total') ?? 0);
                    }
                }
                $csvTotalExempt = $transactions->sum(fn ($t) => $rowSign($t) * ($t->exempt_amount ?? 0));
                $csvExemptOther = max(0, $csvTotalExempt - $csvThirdTotal);
                $csvSaleCount = $transactions->filter(fn ($t) => !$rowIsReturn($t))->count();
                $csvReturns = $transactions->filter($rowIsReturn);

                fputcsv($file, ['SUMMARY — Monthly Return Breakup' . ($returnsOnly ? ' (Credit Notes Only — refunded amounts)' : '')]);
                fputcsv($file, ['Total Invoices', $returnsOnly ? $transactions->count() : $csvSaleCount]);
                fputcsv($file, ['Total Sales Amount (PKR)', number_format($transactions->sum(fn ($t) => $rowSign($t) * $t->total_amount), 2, '.', '')]);
                fputcsv($file, ['Total Discount Amount (PKR)', number_format($transactions->sum(fn ($t) => $rowSign($t) * $t->discount_amount), 2, '.', '')]);
                fputcsv($file, ['Taxable Sales (PKR)', number_format($transactions->sum(fn($t) => $rowSign($t) * ($t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0))), 2, '.', '')]);
                fputcsv($file, ['Third Schedule Sales (PKR)', number_format($csvThirdTotal, 2, '.', '')]);
                fputcsv($file, ['Exempt Sales — Other (PKR)', number_format($csvExemptOther, 2, '.', '')]);
                fputcsv($file, ['Total Tax Exempt Amount (PKR)', number_format($csvTotalExempt, 2, '.', '')]);
                fputcsv($file, ['Total Tax Amount (PKR)', number_format($transactions->sum(fn ($t) => $rowSign($t) * $t->tax_amount), 2, '.', '')]);
                if ($typeReady) {
                    fputcsv($file, ['Credit Notes (count)', $csvReturns->count()]);
                    fputcsv($file, ['Credit Notes Refunded (PKR)', number_format($csvReturns->sum('total_amount'), 2, '.', '')]);
                    fputcsv($file, ['Credit Notes Tax Reversed (PKR)', number_format($csvReturns->sum('tax_amount'), 2, '.', '')]);
                }
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
        [$data, $filename] = $this->buildTaxReportPdfData($request);

        return $this->renderReportPdf('pos.tax-report-pdf', $data, $filename, 'landscape');
    }

    /**
     * Builds the FULL view-data array + filename for the printed tax report PDF.
     * Split out of exportTaxReportPdf (Task 700) so tests can lock the PDF
     * summary figures against the screen's without rendering DomPDF — the
     * owner files taxes from the printed copy, so these MUST never drift.
     */
    protected function buildTaxReportPdfData(Request $request): array
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Local export is ADMIN-ONLY — cashiers always export PRA data.
        // Task 705: manager PRA-only default — Local export needs local-check mode ON.
        $tab = ($request->get('tab') === 'local' && auth('pos')->user()?->isPosAdmin()
            && !(auth('pos')->user()?->posHidesLocalStream() ?? false)) ? 'local' : 'pra';

        $taxRateFilter = $request->filled('tax_rate') ? $request->tax_rate : null;
        // Credit-note netting (Task 695): PDF honors the bill-type filter and
        // mirrors the screen's SIGNED math exactly.
        [$typeReady, $returnsOnly, $signExpr, $saleRowExpr, $isReturnExpr] = $this->taxReportNettingExprs($request);
        $billTypeFilter = $typeReady && in_array($request->get('bill_type'), ['sales', 'returns'], true)
            ? $request->get('bill_type') : '';

        $query = $this->buildTaxReportQuery($request, $tab, (bool)$taxRateFilter);
        $transactions = $query->get();

        $itemValues = [];
        if ($taxRateFilter) {
            $allIds = $transactions->pluck('id')->toArray();
            $summary = $this->buildItemLevelSummary($allIds, $taxRateFilter, $typeReady, $returnsOnly);
            $summary->total_discount = 0;
            $itemValues = $this->getItemLevelValuesForTransactions($transactions, $taxRateFilter);
        } else {
            $summaryQuery = $this->buildTaxReportQuery($request, $tab, false);
            $summary = $summaryQuery->reorder()->selectRaw("
                COALESCE(SUM({$saleRowExpr}), 0) as total_invoices,
                COALESCE(SUM(({$signExpr}) * total_amount), 0) as total_sales,
                COALESCE(SUM(({$signExpr}) * discount_amount), 0) as total_discount,
                COALESCE(SUM(({$signExpr}) * (subtotal - discount_amount - COALESCE(exempt_amount, 0))), 0) as total_taxable,
                COALESCE(SUM(({$signExpr}) * tax_amount), 0) as total_tax,
                COALESCE(SUM(({$signExpr}) * COALESCE(exempt_amount, 0)), 0) as total_exempt,
                COALESCE(SUM({$isReturnExpr}), 0) as return_count,
                COALESCE(SUM(({$isReturnExpr}) * total_amount), 0) as return_amount,
                COALESCE(SUM(({$isReturnExpr}) * tax_amount), 0) as return_tax
            ")->first();

            // Third Schedule breakdown for PDF — SIGNED (returns subtract)
            $pdfThirdTotal = 0;
            if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'is_third_schedule')) {
                $pdfAllIds = $transactions->pluck('id')->toArray();
                if (!empty($pdfAllIds)) {
                    $base3 = $this->itemBaseSqlExpr();
                    $pdfThirdTotal = (float) (\App\Models\PosTransactionItem::query()
                        ->join('pos_transactions', 'pos_transactions.id', '=', 'pos_transaction_items.transaction_id')
                        ->whereIn('pos_transaction_items.transaction_id', $pdfAllIds)
                        ->where('pos_transaction_items.is_third_schedule', true)
                        ->selectRaw("COALESCE(SUM(({$signExpr}) * ({$base3})), 0) as ts_total")
                        ->value('ts_total') ?? 0);
                }
            }
            $summary->total_third_schedule = $pdfThirdTotal;
            $summary->total_exempt_other = max(0, (float) ($summary->total_exempt ?? 0) - $pdfThirdTotal);
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

        $data = compact('company', 'transactions', 'summary', 'dateLabel', 'taxRateLabel', 'taxRateFilter', 'itemValues', 'billTypeFilter') + ['billTypeReady' => $typeReady];

        return [$data, $filename];
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

        // Billing Scope (07 Aug 2026): a scope-locked manager's stream is fixed
        // by the admin — flipping the reporting toggle would sidestep the lock.
        // Task 1186: EXPLICIT scope only — the derived default rides ON the
        // reporting flag, so it must never lock the toggle itself.
        if ($togglingUser->posBillingScopeExplicit() !== 'both') {
            return response()->json([
                'success' => false,
                'enabled' => (bool) $effectiveNow,
                'message' => __('pos.billing_scope_no_toggle'),
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

    /**
     * Task 527 (owner voice notes, 12 Aug 2026): admin-controlled waiter
     * permissions — company-level toggles (waiters are excluded from the
     * per-user Custom Access system):
     *   'cancel'   → pos_waiter_cancel_enabled   (default OFF)
     *   'takeaway' → pos_waiter_takeaway_enabled (default ON)
     */
    public function toggleWaiterPermission(Request $request)
    {
        // Waiter PERMISSION toggles are strictly admin/manager territory —
        // stricter than posCashierBlocked(): a Custom-Access-granted cashier
        // may reach other settings, but must never grant waiters abilities.
        // (PosAuth already confines waiters to pos/waiter* paths; this is the
        // in-controller defense-in-depth so the gate never depends on
        // middleware ordering.)
        $user = auth('pos')->user();
        if (!$user || !$user->isPosAdmin()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }

        $validated = $request->validate([
            'permission' => 'required|in:cancel,takeaway',
            'enabled' => 'required|boolean',
        ]);

        $column = $validated['permission'] === 'cancel'
            ? 'pos_waiter_cancel_enabled'
            : 'pos_waiter_takeaway_enabled';

        $company = Company::find(app('currentCompanyId'));
        $company->{$column} = (bool) $validated['enabled'];
        $company->save();

        return response()->json(['success' => true, 'enabled' => (bool) $company->{$column}]);
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
     * ARCHIVED local bills eligible for permanent cleanup.
     *
     * L-references are monotonic and remain consumed in the durable company
     * counter after these rows are deleted. This selector now controls records
     * only; it never controls whether an old reference can be reused.
     *
     * This is the single selector behind BOTH the Customize POS status line and the
     * clear action, so the count the owner confirms is exactly what gets deleted.
     * ALWAYS read it through archivedLocalSeriesRows() — PosLocalSeries::filter()
     * here is the same coarse prefilter the generators use and the exact
     * /^L-\d+$/ narrowing happens there.
     * Rules are IDENTICAL to the day-close DELETE policy (performDayClose):
     *   - never a PRA row: pra_invoice_number NULL + the two disjoint local sets
     *     (provisional = local+local triple, reporting-OFF final = completed +
     *     pra/NULL mode + NULL pra_status)
     *   - never a return / credit note (would desync the parent + eat quota)
     *   - never an unsettled rider CASH bill (live khata proof)
     * Plus one extra narrowing the wash does not need: only the L-series — legacy
     * "LOCAL-YYYY-NNNNN" rows block nothing, so they are left alone.
     *
     * The non-rider half lives in archivedLocalSeriesBase() so the deletable set
     * and the rider-HELD set (riderHeldLocalSeriesCount, Task 1374) stay literal
     * complements of ONE predicate: whatever this query skips for rider reasons
     * is exactly what that count reports back to the owner.
     */
    private function archivedLocalSeriesQuery(int $companyId)
    {
        $riderGuardReady = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_id');

        return $this->archivedLocalSeriesBase($companyId)
            // Rider khata guard, mirrored in SQL. Written as the NEGATION of the
            // wash's PHP guard ($t->rider_id && cash && !settled && !returned) —
            // note the explicit whereNull('payment_method') leg: `!= 'cash'` alone
            // is NULL-unknown in SQL and would silently protect (i.e. skip) bills
            // the day-close wash happily deletes.
            ->when($riderGuardReady, function ($q) {
                $q->where(function ($g) {
                    $g->whereNull('rider_id')
                        ->orWhere(function ($p) {
                            $p->where('payment_method', '!=', 'cash')->orWhereNull('payment_method');
                        })
                        ->orWhereNotNull('rider_settlement_id')
                        ->orWhere('delivery_status', 'returned');
                });
            });
    }

    /**
     * Everything an archived L-series bill is, EXCEPT the rider decision — see
     * archivedLocalSeriesQuery() above for the full contract.
     */
    private function archivedLocalSeriesBase(int $companyId)
    {
        $query = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('is_archived', true)
            ->whereNull('pra_invoice_number');

        // Same series prefilter (and same legacy "LOCAL-YYYY" exclusion) the two
        // generators and the preview use — one definition of "an L-series bill".
        return \App\Services\PosLocalSeries::filter($query)
            ->where(function ($w) {
                $w->where(function ($prov) {
                    $prov->where('invoice_mode', 'local')->where('pra_status', 'local');
                })->orWhere(function ($fin) {
                    $fin->where('status', 'completed')
                        ->where(function ($m) {
                            $m->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
                        })
                        ->whereNull('pra_status');
                });
            })
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type'), function ($q) {
                $q->where(function ($t) {
                    $t->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
                });
            });
    }

    /**
     * The archived L-series bills the clear DELIBERATELY keeps back because a
     * rider's cash for them is still unsettled (Task 1374).
     *
     * Exactly the complement of the rider guard above — i.e. the live khata
     * predicate (PosRider::openCashBills): rider set + cash + not settled + not
     * returned — narrowed to real /^L-\d+$/ serials, because only those actually
     * identify an exact L-series bill. The figure explains why those archived
     * records remain after a clear; it no longer affects the next L-reference.
     */
    private function riderHeldLocalSeriesCount(int $companyId): int
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_archived')
                || !\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_id')) {
                return 0;
            }

            return $this->archivedLocalSeriesBase($companyId)
                ->whereNotNull('rider_id')
                ->where('payment_method', 'cash')
                ->whereNull('rider_settlement_id')
                ->where(function ($d) {
                    $d->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
                })
                ->get(['id', 'invoice_number'])
                ->filter(fn ($t) => \App\Services\PosLocalSeries::isSeriesSerial($t->invoice_number))
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * The archived L-series rows, narrowed to EXACT L-NNN serials (Task 1358).
     *
     * The SQL `like 'L%'` in archivedLocalSeriesQuery() is only a coarse prefilter:
     * both generators (and the preview below) treat ONLY /^L-?\d+$/ as an occupied
     * number, so anything else that happens to start with "L" — a hand-typed
     * "L001-extra", a legacy "LDRAFT" — reserves nothing. Such a bill must never
     * be counted as a blocker or swept up by the (permanent) clear. The narrowing
     * runs through PosLocalSeries::isSeriesSerial(), the same test the generators
     * apply, and preg_match keeps that contract identical on MySQL and sqlite,
     * where REGEXP support differs.
     *
     * Every figure the owner is shown AND the deletion itself come from this one
     * set, so the confirmed count is exactly what gets deleted.
     */
    private function archivedLocalSeriesRows(int $companyId, array $columns = ['*'], bool $lock = false)
    {
        return $this->archivedLocalSeriesQuery($companyId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get($columns)
            ->filter(fn ($t) => \App\Services\PosLocalSeries::isSeriesSerial($t->invoice_number))
            ->values();
    }

    /**
     * Read-only preview of the next L-number (Task 1358) — the SAME helper the two
     * sale paths issue from (\App\Services\PosLocalSeries), so the number promised on
     * the Customize POS card is the number the sale screen actually prints.
     * Exclusions remain for caller compatibility but no longer rewind numbering.
     */
    private function previewNextLocalNumber(int $companyId, array $excludeIds = []): string
    {
        return \App\Services\PosLocalSeries::previewNext($companyId, $excludeIds);
    }

    /**
     * Status figures for the Customize POS → Local Billing card (Task 1358):
     * how many archived local bills can be cleared, over which dates, and the
     * monotonic next number (which stays unchanged by the clear).
     * Fully schema-guarded — a prod box mid-deploy just gets count 0 (card hides).
     */
    private function localSeriesStatus(int $companyId): array
    {
        $first = \App\Services\PosLocalSeries::format(1);
        $empty = ['count' => 0, 'from' => null, 'to' => null, 'next' => $first, 'next_after' => $first, 'can_reset' => false];
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_archived')) {
                return $empty;
            }
            $rows = $this->archivedLocalSeriesRows($companyId, ['id', 'invoice_number', 'business_date', 'created_at']);
            // Fresh-start offer (owner, 25 Aug 2026): sirf tab jab series mein ek
            // bhi bill baqi na ho (archived samet) AUR counter aage barha hua ho.
            // Bill maujood hote hue reset ka matlab do bilon par ek hi reference.
            $canReset = !\App\Services\PosLocalSeries::hasIssuedRows($companyId)
                && $this->previewNextLocalNumber($companyId) !== \App\Services\PosLocalSeries::format(1);
            if ($rows->isEmpty()) {
                return array_merge($empty, [
                    'next' => $this->previewNextLocalNumber($companyId),
                    'can_reset' => $canReset,
                ]);
            }
            // business_date is the trading day the bill belongs to; pre-column rows
            // (or NULLs) fall back to the calendar date.
            $dates = $rows->map(fn ($t) => $t->business_date ?: $t->created_at?->toDateString())
                ->filter()->sort()->values();
            $ids = $rows->pluck('id')->all();

            return [
                'count' => $rows->count(),
                'from' => $dates->first(),
                'to' => $dates->last(),
                'next' => $this->previewNextLocalNumber($companyId),
                'next_after' => $this->previewNextLocalNumber($companyId, $ids),
                'can_reset' => $canReset,
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * How many customer-spend record lines this company currently carries.
     *
     * These are the rows written just before a local bill is DELETED at day
     * close, so the customer's history keeps the amount even though the bill is
     * gone. Schema-guarded: a box mid-deploy simply reports 0.
     */
    private function customerSpendRecordCount(int $companyId): int
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_customer_spend_snapshots')) {
                return 0;
            }

            return (int) \App\Models\PosCustomerSpendSnapshot::where('company_id', $companyId)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Customize POS → Local Billing — permanently remove the leftover
     * customer-spend record lines of already-deleted local bills.
     *
     * The spend switch only stops NEW lines; the owner's point (25 Aug 2026) is
     * that a daily delete means nothing if yesterday's line is still visible in
     * customer history. ADMIN/OWNER only, never automatic, and confirmed with a
     * count first. Real bills are untouched — these rows are not transactions,
     * carry no items, and appear in no report.
     */
    public function clearCustomerSpendRecords(Request $request)
    {
        // Same bar as clearArchivedLocalBills: this is a permanent delete, so
        // custom-access cashiers are out too.
        $user = auth('pos')->user();
        if (!$user || !$user->isPosAdmin()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }

        $companyId = (int) app('currentCompanyId');
        if (!\Illuminate\Support\Facades\Schema::hasTable('pos_customer_spend_snapshots')) {
            return response()->json(['success' => false, 'message' => __('pos.setting_save_failed')], 503);
        }

        $deleted = (int) \App\Models\PosCustomerSpendSnapshot::where('company_id', $companyId)->delete();

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => __('pos.spend_records_cleared', ['count' => $deleted]),
        ]);
    }

    /**
     * Customize POS → Local Billing — permanently clear archived local records.
     * ADMIN/OWNER only and never automatic: the owner confirms a count + date
     * range first. The durable L-series high-water mark is preserved.
     *
     * Deletes exactly what archivedLocalSeriesRows() selects, following the
     * day-close DELETE policy step for step: customer spend snapshots FIRST (when
     * the company keeps the spend record), then items + payments, then the bills.
     * Deleted CURRENT-MONTH reporting-OFF finals are written to the
     * pos_local_series_resets ledger so PlanLimitService can add them back —
     * clearing must never become a way to buy back monthly bill quota.
     */
    public function clearArchivedLocalBills(Request $request)
    {
        // Stricter than the sibling settings endpoints' posCashierBlocked(): this
        // permanently deletes bills, so custom-access cashiers are out too.
        $user = auth('pos')->user();
        if (!$user || !$user->isPosAdmin()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }

        $companyId = (int) app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) {
            return response()->json(['success' => false, 'message' => __('pos.setting_save_failed')], 404);
        }
        if (!\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_archived')) {
            return response()->json(['success' => false, 'message' => __('pos.setting_save_failed')], 503);
        }

        $spendPersist = (bool) ($company->pos_customer_spend_persist ?? true);
        // Quota month bounds — basis MUST be created_at, not business_date.
        // The ledger row is stamped with TODAY's reset_date, and PlanLimitService
        // adds it back for the month that date falls in, so this count has to be
        // exactly "bills the CURRENT month's live count was counting" — and that
        // live count is `whereBetween('created_at', [startOfMonth, endOfMonth])`.
        // Boundary that business_date would get wrong: a final created 1 Aug 00:30
        // with business_date 31 Jul is inside AUGUST's live quota; counting it as
        // July would delete it with no add-back and hand the shop a free bill.
        // (The day-close wash filters by business_date because its credit month is
        // the REPORT month, not the month the wash runs in — different anchor.)
        // Earlier months stay excluded: their quota month is already over.
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $inThisMonth = function ($t) use ($monthStart, $monthEnd) {
            return $t->created_at && $t->created_at >= $monthStart && $t->created_at <= $monthEnd;
        };
        $isProvisional = fn ($t) => $t->invoice_mode === 'local' && $t->pra_status === 'local';

        $deleted = 0;
        \DB::transaction(function () use ($companyId, $spendPersist, $inThisMonth, $isProvisional, $user, &$deleted) {
            // Lock and preserve the highest reference BEFORE selecting/deleting
            // rows. This also protects manually imported or pre-migration bills.
            \App\Services\PosLocalSeries::preserveHighWaterMark($companyId);

            // Candidates are selected INSIDE the transaction and locked (SELECT ...
            // FOR UPDATE). A second admin — or a double-clicked / replayed POST —
            // blocks here until this commit and then reads an empty set, so the
            // permanent delete can never write duplicate spend snapshots or a second
            // quota ledger row (which would overstate the shop's monthly usage).
            // Snapshots, delete IDs and ledger counts all come from these locked rows.
            $rows = $this->archivedLocalSeriesRows($companyId, ['*'], true);
            if ($rows->isEmpty()) {
                return;
            }
            $ids = $rows->pluck('id')->all();
            $dates = $rows->map(fn ($t) => $t->business_date ?: $t->created_at?->toDateString())->filter()->sort()->values();

            if ($spendPersist && \Illuminate\Support\Facades\Schema::hasTable('pos_customer_spend_snapshots')) {
                $now = now();
                $snapshots = $rows
                    ->filter(fn ($t) => $t->customer_id || !empty($t->customer_phone))
                    ->map(fn ($t) => [
                        'company_id' => $companyId,
                        'customer_id' => $t->customer_id,
                        'customer_phone' => $t->customer_phone,
                        'customer_name' => $t->customer_name,
                        'invoice_number' => $t->invoice_number,
                        'bill_kind' => $isProvisional($t) ? 'provisional' : 'final_local',
                        'payment_method' => $t->payment_method,
                        'subtotal' => $t->subtotal,
                        'discount_amount' => $t->discount_amount,
                        'tax_amount' => $t->tax_amount,
                        'total_amount' => $t->total_amount,
                        'sold_at' => $t->created_at,
                        // No day-close report behind this one — the ledger row
                        // below is the audit trail instead.
                        'dayclose_report_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->values()->all();
                if (!empty($snapshots)) {
                    \App\Models\PosCustomerSpendSnapshot::insert($snapshots);
                }
            }

            \App\Models\PosTransactionItem::whereIn('transaction_id', $ids)->delete();
            \App\Models\PosPayment::whereIn('transaction_id', $ids)->delete();
            $deleted = PosTransaction::withoutGlobalScope('hide_archived')->whereIn('id', $ids)->delete();

            $thisMonth = $rows->filter($inThisMonth);
            \App\Models\PosLocalSeriesReset::create([
                'company_id' => $companyId,
                'reset_date' => now()->toDateString(),
                'deleted_final_count' => $thisMonth->reject($isProvisional)->count(),
                'deleted_provisional_count' => $thisMonth->filter($isProvisional)->count(),
                'total_deleted' => $rows->count(),
                'from_date' => $dates->first(),
                'to_date' => $dates->last(),
                'performed_by' => $user->id,
            ]);
        });

        $next = $this->previewNextLocalNumber($companyId);

        // Explain which archived records were deliberately spared because rider
        // cash is unsettled. Numbering itself remains monotonic either way.
        $riderHeld = $this->riderHeldLocalSeriesCount($companyId);
        $riderKeptMessage = $riderHeld > 0
            ? __('pos.local_series_rider_kept', ['count' => $riderHeld])
            : null;

        // Nothing was there to clear — either the card was stale or another admin
        // (or a replayed POST) already did it. Never a ledger row, never an error.
        if ($deleted === 0) {
            return response()->json([
                'success' => true,
                'deleted' => 0,
                'next_number' => $next,
                'message' => __('pos.local_series_nothing_to_clear'),
                'rider_held' => $riderHeld,
                'rider_held_message' => $riderKeptMessage,
            ]);
        }

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
            'next_number' => $next,
            'message' => __('pos.local_series_cleared_done', ['count' => $deleted, 'next' => $next]),
            'rider_held' => $riderHeld,
            'rider_held_message' => $riderKeptMessage,
        ]);
    }

    /**
     * Customize POS → Local Billing — start the local reference series over at
     * L001 (owner request, 25 Aug 2026).
     *
     * Pas-manzar: shop ne saray provisional/local record clear kar diye, token
     * agle din 1 se shuru hue, magar reference L-016 se aage chalta raha —
     * "numbering 1 par reset karne ka option chahiye". Monotonic usool waisa hi
     * hai (clear, delete, day-close, archive — koi bhi cheez numbering peechay
     * nahi le jati); yeh ek alag, jaan-boojh kar kiya gaya admin amal hai.
     *
     * Sakht shart: series KHALI ho — ek bhi L-reference wala bill (archived
     * samet) baqi ho to reset nahi hota, warna do bill ek hi reference le kar
     * ghoomenge. PRA/fiscal serial (POS-YYYY-NNNNN) yahan se nahi badalta: wo
     * mojooda bilon se derive hota hai, aur report-shuda bill delete nahi hote.
     */
    public function resetLocalNumbering(Request $request)
    {
        // Same bar as the clear: this rewrites how every future bill is
        // numbered, so custom-access cashiers/managers are out.
        $user = auth('pos')->user();
        if (!$user || !$user->isPosAdmin()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }

        $companyId = (int) app('currentCompanyId');
        if (!Company::find($companyId)) {
            return response()->json(['success' => false, 'message' => __('pos.setting_save_failed')], 404);
        }

        // Re-check INSIDE the transaction (and under the sale lock the service
        // takes): the card the admin clicked may be minutes old, and a cashier
        // may have billed since.
        $done = DB::transaction(fn () => \App\Services\PosLocalSeries::resetToStart($companyId));

        if (!$done) {
            return response()->json([
                'success' => false,
                'message' => __('pos.local_series_reset_blocked'),
            ], 409);
        }

        \Illuminate\Support\Facades\Log::info('POS local series reset to its first number', [
            'company_id' => $companyId,
            'by' => $user->id,
        ]);

        $next = $this->previewNextLocalNumber($companyId);

        return response()->json([
            'success' => true,
            'next_number' => $next,
            'message' => __('pos.local_series_reset_done', ['next' => $next]),
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
     * Persist the independent clock used by the hourly auto day-close sweep.
     * This intentionally does not change the business-day cutoff.
     */
    public function updateAutoDaycloseTime(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }

        $time = (string) $request->input('time', '');
        if (!preg_match('/^([01]\d):(00|30)$/', $time) || $time >= '12:00') {
            return response()->json(['success' => false, 'message' => __('pos.dayclose_time_range')], 422);
        }

        $company = Company::find(app('currentCompanyId'));
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_auto_dayclose_time')) {
            return response()->json(['success' => false, 'message' => __('pos.setting_not_available_try_later')], 503);
        }
        if ($time < \App\Services\PosBusinessDay::cutoffFor($company->id)) {
            return response()->json(['success' => false, 'message' => __('pos.auto_dayclose_time_after_cutoff')], 422);
        }

        $company->pos_auto_dayclose_time = $time;
        $company->save();
        \App\Services\PosBusinessDay::forgetAutoCloseTime($company->id);

        return response()->json([
            'success' => true,
            'time' => $company->pos_auto_dayclose_time,
            'message' => __('pos.auto_dayclose_time_saved'),
        ]);
    }

    /**
     * Company policy for delivery bills that were never assigned to a rider.
     * The same policy is read by the manual close, hourly auto-close, bulk
     * close and recovery paths.
     */
    public function updateUnassignedDeliveryDayclose(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }

        $validated = $request->validate([
            'action' => 'required|in:allow,block',
        ]);

        $company = Company::find(app('currentCompanyId'));
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_dayclose_unassigned_delivery_action')) {
            return response()->json(['success' => false, 'message' => __('pos.setting_not_available_try_later')], 503);
        }

        $company->pos_dayclose_unassigned_delivery_action = $validated['action'];
        $company->save();

        return response()->json([
            'success' => true,
            'action' => $company->pos_dayclose_unassigned_delivery_action,
            'message' => __('pos.unassigned_delivery_dayclose_saved'),
        ]);
    }

    /**
     * Customize POS → persist "Cashier bhi Day Close kar sake" (owner rule,
     * 5 Aug 2026). Default OFF = Day Close is admin/manager work; this switch
     * re-opens it for cashiers on ANY plan (Team Custom Access stays the
     * per-member override on Unlimited). Verdict: PosAccessService::dayCloseAllowed.
     */
    public function toggleCashierDayclose(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        // PROD drift guard: code can land before the migration on live — fail
        // gracefully instead of a SQL "unknown column" 500.
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_cashier_dayclose')) {
            return response()->json(['success' => false, 'message' => __('pos.setting_save_failed')], 503);
        }
        $company = Company::find(app('currentCompanyId'));
        $company->pos_cashier_dayclose = $request->boolean('enabled');
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->pos_cashier_dayclose,
            'message' => $company->pos_cashier_dayclose ? __('pos.cashier_dayclose_enabled') : __('pos.cashier_dayclose_disabled'),
        ]);
    }

    /**
     * Customize POS → persist "Cashier bhi Order Cancel kar sake" (Task #643,
     * owner voice note 13 Aug 2026). Default OFF = restaurant order cancel is
     * owner/manager work; this switch re-opens it for cashiers on ANY plan
     * (Team Custom Access stays the per-member override on Unlimited).
     * Verdict: PosAccessService::orderCancelAllowed.
     */
    public function toggleCashierOrderCancel(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            return response()->json(['success' => false, 'message' => __('pos.only_admin_change_setting')], 403);
        }
        // PROD drift guard: code can land before the migration on live — fail
        // gracefully instead of a SQL "unknown column" 500.
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_cashier_order_cancel')) {
            return response()->json(['success' => false, 'message' => __('pos.setting_save_failed')], 503);
        }
        $company = Company::find(app('currentCompanyId'));
        $company->pos_cashier_order_cancel = $request->boolean('enabled');
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->pos_cashier_order_cancel,
            'message' => $company->pos_cashier_order_cancel ? __('pos.cashier_ordercancel_enabled') : __('pos.cashier_ordercancel_disabled'),
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

        // Task 1349: package limit vs current usage. Platform convention (same
        // as CheckPlanLimit / PlanLimitService): NULL or negative = UNLIMITED.
        // Only ACTIVE counters count against the cap, exactly like the guard on
        // the create route — so a deactivated counter frees a slot.
        $terminalLimit = null;
        $subscription = \App\Models\Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();
        if ($subscription && $subscription->pricingPlan
            && $subscription->pricingPlan->max_terminals !== null
            && (int) $subscription->pricingPlan->max_terminals >= 0) {
            $terminalLimit = (int) $subscription->pricingPlan->max_terminals;
        }
        $terminalsActive = $terminals->where('is_active', true)->count();

        // Aaj ki sale per counter — business day (00:00–05:59 pre-close bills
        // yesterday mein ginay jate hain), returns netted like every other
        // POS figure. Keyed by terminal_id for the table below.
        $todayByTerminal = collect();
        if ($terminals->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'terminal_id')) {
            $bizToday = \App\Services\PosBusinessDay::current((int) $companyId);
            $signExpr = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type')
                ? "CASE WHEN transaction_type = 'return' THEN -1 ELSE 1 END"
                : '1';
            $todayByTerminal = PosTransaction::where('company_id', $companyId)
                ->where('status', 'completed')
                ->whereNotNull('terminal_id')
                ->where('business_date', $bizToday)
                ->groupBy('terminal_id')
                ->selectRaw("terminal_id, COUNT(*) as bills, COALESCE(SUM(({$signExpr}) * total_amount),0) as sale")
                ->get()
                ->keyBy('terminal_id');
        }

        return view('pos.terminals', compact('terminals', 'terminalLimit', 'terminalsActive', 'todayByTerminal'));
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

        // Multi-branch v1 (Task 1347): the branch column + per-member branch
        // selector only appear once the company actually has branches.
        // hasTable guard mirrors BranchContextService::branchesReady() — the
        // team page must survive a deployment whose branch migration is missing.
        $branches = \Illuminate\Support\Facades\Schema::hasTable('branches')
            ? \App\Models\Branch::where('company_id', $companyId)
                ->orderByDesc('is_head_office')->orderBy('name')->get()
            : collect();

        return view('pos.team', compact('team', 'teamPasswords', 'company', 'branches'));
    }

    /**
     * Company-owned branch id from a team request, or null (main shop).
     * Twin of FbrPosController::fbrResolveBranchId (Task 1347).
     */
    private function posResolveBranchId(Request $request, int $companyId): ?int
    {
        if (!$request->filled('default_branch_id')) {
            return null;
        }
        $branchId = (int) $request->default_branch_id;
        return \App\Models\Branch::where('company_id', $companyId)->where('id', $branchId)->exists()
            ? $branchId : null;
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
            // Task 529: optional short login name — staff can log in with it
            // instead of the full email (LoginIdentifierResolver already
            // resolves users.username). Globally unique (column has a global
            // unique index); no spaces/@ so it can never look like an email.
            'username' => \App\Services\LoginIdentifierResolver::usernameRules(),
            // Multi-branch v1 (Task 1347): validated for shape only — ownership
            // is re-checked against the company in posResolveBranchId().
            'default_branch_id' => 'nullable|integer',
        ], [
            ...\App\Services\LoginIdentifierResolver::usernameMessages(),
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
                return back()->with('error', \App\Services\SubscriptionAccessService::localizedLockReason($quota['reason']));
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
        // Task 529: optional login username. Blank → NULL (never empty string —
        // the global unique index must keep allowing many username-less
        // accounts). hasColumn = schema-drift guard, same as the enc copy.
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'username')) {
            $newUserData['username'] = $request->input('username') ?: null;
        }
        // Billing Scope (07 Aug 2026): cashier/manager accounts can be locked to
        // one stream at creation. Task 1186: 'auto' (= NULL column) is the new
        // cashier default — the effective scope derives from reporting status.
        // Explicit 'both' is the owner's OFF switch (unrestricted, purana view).
        // Owner-only rule (07 Aug 2026): sirf owner (ya owner ka allow kiya hua
        // admin) hi scope set kar sakta hai — baqi sab ka input silently ignore.
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_billing_scope')
            && $user->canManageBillingScope()
            && in_array($newRole, ['pos_cashier', 'pos_manager'], true)
            && in_array($request->input('pos_billing_scope'), ['both', 'local', 'pra'], true)) {
            $newUserData['pos_billing_scope'] = $request->input('pos_billing_scope');
            // Scope ↔ reporting alignment: a 'pra'-locked account MUST report
            // (reporting-OFF finals are local-stream → their own guard would
            // brick billing); a 'local'-locked account must NOT. 'both' inherits.
            if ($newUserData['pos_billing_scope'] === 'pra') {
                $newUserData['pra_reporting_enabled'] = true;
            } elseif ($newUserData['pos_billing_scope'] === 'local') {
                $newUserData['pra_reporting_enabled'] = false;
            }
        }
        $newUser = User::create($newUserData);

        // Multi-branch v1 (Task 1347): default branch of the new account. NOT in
        // User::$fillable — a create() key would be silently dropped, so it is
        // assigned directly after the row exists. hasColumn = prod-drift guard.
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'default_branch_id')) {
            $newUser->default_branch_id = $this->posResolveBranchId($request, (int) $companyId);
            $newUser->save();
        }

        // Audit: billing scope set at creation (null → value).
        if (!empty($newUserData['pos_billing_scope'])) {
            AuditLogService::log(
                'pos_billing_scope_set',
                'User',
                $newUser->id,
                null,
                ['pos_billing_scope' => $newUserData['pos_billing_scope'], 'target_name' => $newUser->name],
                $companyId,
                auth('pos')->id()
            );
        }

        $roleLabel = ['pos_manager' => __('pos.role_manager'), 'pos_kitchen' => __('pos.role_kitchen'), 'pos_waiter' => __('pos.role_waiter'), 'pos_delivery' => __('pos.role_delivery_manager')][$newRole] ?? __('pos.role_cashier');
        return back()->with('success', __('pos.account_created_success', ['role' => $roleLabel]));
    }

    /**
     * Team page — ASSIGN a cashier's PRA Reporting status (owner rule 20 Jul 2026):
     * cashiers can no longer flip their own toggle on the sale screen; the admin
     * sets each cashier Online (PRA reporting) or Offline here. Managers/admins
     * keep their own sale-screen toggle, so this endpoint covers cashiers only.
     */
    /**
     * Billing Scope permission switch (owner request 07 Aug 2026): scope ka
     * ikhtiyar by default sirf company OWNER ke paas hai; yeh switch ON karke
     * owner apne managers/admins ko bhi Billing Scope dene deta hai. Sirf
     * owner (base role company_admin) hi is switch ko chhoo sakta hai.
     */
    public function setBillingScopePermission(Request $request)
    {
        $user = auth('pos')->user();
        if (($user->role ?? null) !== 'company_admin') {
            abort(403);
        }
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'billing_scope_admin_enabled')) {
            return back()->with('error', __('pos.unknown_error'));
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Direct assignment + save (pos_custom_access pattern): update() on a
        // non-$fillable column silently drops.
        $oldEnabled = (bool) $company->billing_scope_admin_enabled;
        $newEnabled = $request->boolean('enabled');
        $company->billing_scope_admin_enabled = $newEnabled;
        $company->save();
        // Audit: always log the toggle (permission grant/revoke is security-relevant).
        AuditLogService::log(
            'pos_billing_scope_permission_toggled',
            'Company',
            $companyId,
            ['billing_scope_admin_enabled' => $oldEnabled],
            ['billing_scope_admin_enabled' => $newEnabled],
            $companyId,
            auth('pos')->id()
        );
        return back()->with('success', __('pos.billing_scope_perm_saved'));
    }

    /**
     * Task 1197 — "Cashier sirf apni sale dekhe" switch (owner-only, Team
     * page): DEFAULT ON for every company (missing/NULL column reads as ON —
     * User::posSalesIsolated). OFF restores the old shared visibility for
     * this shop. Mirrors setBillingScopePermission: base role company_admin
     * only, schema-drift guarded, direct assignment + save (non-$fillable
     * column), always audited.
     */
    public function setCashierOwnSales(Request $request)
    {
        $user = auth('pos')->user();
        if (($user->role ?? null) !== 'company_admin') {
            abort(403);
        }
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_cashier_own_sales_only')) {
            return back()->with('error', __('pos.unknown_error'));
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // NULL (pre-backfill row) reads as ON — audit the EFFECTIVE old value.
        $oldEnabled = (bool) ($company->pos_cashier_own_sales_only ?? true);
        $newEnabled = $request->boolean('enabled');
        $company->pos_cashier_own_sales_only = $newEnabled;
        $company->save();
        AuditLogService::log(
            'pos_cashier_own_sales_toggled',
            'Company',
            $companyId,
            ['pos_cashier_own_sales_only' => $oldEnabled],
            ['pos_cashier_own_sales_only' => $newEnabled],
            $companyId,
            auth('pos')->id()
        );
        return back()->with('success', __('pos.cashier_own_sales_saved'));
    }

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

        // Billing Scope (07 Aug 2026): a stream-locked account's reporting flag
        // is welded to its scope — flipping it the other way would brick billing
        // (the sale-path scope guard rejects the resulting stream). Change the
        // scope from the edit row instead.
        // Task 1186: EXPLICIT scope only — a derived-default cashier's visible
        // stream FOLLOWS this flip (that's the consistency), so the weld must
        // never 403 an unset-scope cashier.
        $cashierScope = $cashier->posBillingScopeExplicit();
        if (($cashierScope === 'pra' && !$enable) || ($cashierScope === 'local' && $enable)) {
            return back()->with('error', __('pos.billing_scope_pra_locked'));
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
            // Task 529: admin can set/change the member's login username from
            // the edit row (own row exempt from the unique check).
            'username' => \App\Services\LoginIdentifierResolver::usernameRules($cashier->id),
            // Multi-branch v1 (Task 1347) — ownership re-checked in posResolveBranchId().
            'default_branch_id' => 'nullable|integer',
        ], [
            ...\App\Services\LoginIdentifierResolver::usernameMessages(),
        ]);

        $cashierData = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
        ];
        // Task 529: blank clears the username (back to email-only login) —
        // NULL, never ''. hasColumn = schema-drift guard.
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'username')) {
            $cashierData['username'] = $request->input('username') ?: null;
        }
        $cashier->update($cashierData);

        // Multi-branch v1 (Task 1347): default branch of this account. Same
        // direct-assignment reason as the billing scope below — the column is
        // not $fillable, so update() would silently drop it. Only honoured when
        // the form actually carried the field (branch UI hidden = untouched).
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'default_branch_id')
            && $request->has('default_branch_id')) {
            $cashier->default_branch_id = $this->posResolveBranchId($request, (int) $companyId);
            $cashier->save();
        }

        // Billing Scope (07 Aug 2026): stream lock is editable from the team edit
        // row — cashier + manager only. Direct assignment (pos_custom_access
        // pattern): update() on a non-$fillable column silently drops.
        // Owner-only rule (07 Aug 2026): sirf owner (ya owner ka allow kiya hua
        // admin) hi scope badal sakta hai — baqi sab ka input silently ignore.
        // Task 1186: 'auto' = wapas derived default (NULL column) — cashier ki
        // effective stream reporting status se khud derive hoti hai. Explicit
        // 'both' = owner ka OFF switch (unrestricted, purana view).
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_billing_scope')
            && (auth('pos')->user()?->canManageBillingScope() ?? false)
            && in_array($cashier->pos_role, ['pos_cashier', 'pos_manager'], true)
            && in_array($request->input('pos_billing_scope'), ['auto', 'both', 'local', 'pra'], true)) {
            $newScope = $request->input('pos_billing_scope');
            if ($newScope === 'auto') {
                $newScope = null; // NULL = derived default (cashier); manager NULL = 'both'
            }
            $oldScope = $cashier->pos_billing_scope;
            $cashier->pos_billing_scope = $newScope;
            // Scope ↔ reporting alignment: 'pra' lock forces reporting ON,
            // 'local' lock forces it OFF — otherwise the scope guard on the
            // sale paths would brick the account. 'both' keeps the current flag.
            if ($newScope === 'pra') {
                $cashier->pra_reporting_enabled = true;
            } elseif ($newScope === 'local') {
                $cashier->pra_reporting_enabled = false;
            }
            $cashier->save();
            // Audit: only log when the scope value actually changed.
            if ($oldScope !== $newScope) {
                AuditLogService::log(
                    'pos_billing_scope_changed',
                    'User',
                    $cashier->id,
                    ['pos_billing_scope' => $oldScope, 'target_name' => $cashier->name],
                    ['pos_billing_scope' => $newScope, 'target_name' => $cashier->name],
                    $companyId,
                    auth('pos')->id()
                );
            }
        }

        // Task 705: PRA counterpart link — LOCAL cashier ↔ PRA cashier pair for
        // the khufia station identity switch. Owner-only (billing-scope
        // visibility rule); cashier-role-only, same-company; target must be
        // able to bill the PRA stream. Empty = clear; invalid input silently
        // ignored (keeps the old link). Direct assignment (non-$fillable).
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_counterpart_user_id')
            && (auth('pos')->user()?->canManageBillingScope() ?? false)
            && $cashier->pos_role === 'pos_cashier'
            && $request->exists('pos_counterpart_user_id')) {
            $cpInput = $request->input('pos_counterpart_user_id');
            $oldCp = $cashier->pos_counterpart_user_id ? (int) $cashier->pos_counterpart_user_id : null;
            $newCp = $oldCp; // invalid target = keep the old link
            if ($cpInput === null || $cpInput === '') {
                $newCp = null;
            } elseif ((int) $cpInput !== $cashier->id) {
                $cpTarget = User::where('company_id', $companyId)
                    ->where('id', (int) $cpInput)
                    ->where('pos_role', 'pos_cashier') // NEVER manager/owner/admin
                    ->first();
                if ($cpTarget && $cpTarget->posBillingScopeExplicit() !== 'local') {
                    $newCp = (int) $cpTarget->id;
                }
            }
            if ($oldCp !== $newCp) {
                $cashier->pos_counterpart_user_id = $newCp;
                $cashier->save();
                AuditLogService::log(
                    'pos_counterpart_link_changed',
                    'User',
                    $cashier->id,
                    ['pos_counterpart_user_id' => $oldCp, 'target_name' => $cashier->name],
                    ['pos_counterpart_user_id' => $newCp, 'target_name' => $cashier->name],
                    $companyId,
                    auth('pos')->id()
                );
            }
        }

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
                return back()->with('error', \App\Services\SubscriptionAccessService::localizedLockReason($quota['reason']));
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
        // Usage-vs-cap banner (Task 362): visibility for shops at/over their
        // plan's product cap (e.g. after a downgrade). null = unlimited.
        $productLimitStatus = \App\Services\PlanLimitService::productLimitStatus($companyId, 'pos');

        // Per-branch stock (Task 1354): the catalogue is company-wide but the
        // stock figure belongs to a shop. Overlay each row with the quantity of
        // the branch the user is standing in; on the owner's all-branches view
        // the mirror already holds the company total, so it is left as-is (and
        // the page marks the column read-only there).
        $stockBranchId = \App\Services\BranchStockService::viewBranchId($companyId);
        $stockAllBranches = \App\Services\BranchStockService::viewingAllBranches($companyId);
        $stockBranchName = \App\Services\BranchStockService::branchName($companyId, $stockBranchId);
        if ($stockBranchId && $company && $company->inventory_enabled) {
            $branchQty = \App\Services\BranchStockService::quantities(
                $companyId, $stockBranchId, $products->pluck('id')->all()
            );
            foreach ($products as $p) {
                // NULL stays NULL — "untracked" must never become 0.
                if ($p->stock_quantity !== null) {
                    $p->stock_quantity = (int) round($branchQty[$p->id] ?? 0);
                }
            }
        }

        return view('pos.products', compact(
            'products', 'posType', 'categoryFields', 'ingredients', 'existingRecipes',
            'company', 'productLimitStatus', 'stockBranchId', 'stockBranchName', 'stockAllBranches'
        ));
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
        // Subscription access gate (Task 362): this route has NO plan.limit
        // middleware, so the middleware's Step-1 access check is applied here —
        // suspended/expired/trial-ended shops are blocked before any write.
        // FAIL CLOSED; the only pass-through is the schema-compat guard.
        if (\Illuminate\Support\Facades\Schema::hasTable('subscriptions')) {
            $access = \App\Services\SubscriptionAccessService::hasAccess($company);
            if (!$access['allowed']) {
                return response()->json(['ok' => false, 'error' => \App\Services\SubscriptionAccessService::localizedLockReason($access['reason'])], 403);
            }
        }
        // Plan product cap (Task 362): this route has NO plan.limit middleware —
        // without this gate an at-cap (or over-cap after downgrade) shop could
        // keep adding products one-by-one from the sale screen.
        // 🔒 ATOMIC QUOTA ADMISSION (same pattern as importProducts): allowance
        // count + insert run in ONE transaction under a company-row lock, so two
        // simultaneous quick-creates at the last free slot serialize — the
        // second recounts AFTER the first commits and can never exceed the cap.
        try {
            $product = DB::transaction(function () use ($companyId, $name, $data) {
                Company::where('id', $companyId)->lockForUpdate()->get();
                $remaining = \App\Services\PlanLimitService::remainingProductAllowance($companyId, 'pos');
                if ($remaining !== null && $remaining <= 0) {
                    throw new \App\Exceptions\PlanLimitReachedException();
                }
                return PosProduct::create([
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
            });
        } catch (\App\Exceptions\PlanLimitReachedException $e) {
            return response()->json([
                'ok' => false,
                'error' => __('pos.product_limit_reached_error'),
                'reason' => 'plan_limit',
            ], 403);
        }
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
        $isThirdSchedule = $request->has('is_third_schedule');
        // Third Schedule → always tax-free (also marks exempt)
        if ($isThirdSchedule) { $isExempt = true; }
        $data = [
            'company_id' => $companyId,
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'cost_price' => $request->filled('cost_price') ? $request->cost_price : 0,
            'stock_quantity' => $request->filled('stock_quantity') ? (int) $request->stock_quantity : null,
            'low_stock_threshold' => $request->filled('low_stock_threshold') ? (int) $request->low_stock_threshold : 10,
            // Backend hardening: exempt/third-schedule MUST persist tax_rate=0
            'tax_rate' => $isExempt ? 0 : ($request->tax_rate ?? 0),
            'category' => $request->category,
            'sku' => $request->sku,
            'barcode' => $request->barcode,
            'uom' => $request->uom ?? 'NOS',
            'is_tax_exempt' => $isExempt,
            'is_third_schedule' => $isThirdSchedule,
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
                // Per-branch stock (Task 1354): opening stock lands in the shop
                // the product was added from, not in a company-wide pile.
                $stockBranchId = \App\Services\BranchStockService::writeBranchId($companyId);
                $stockRow = \App\Models\InventoryStock::firstOrCreate(
                    ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => $stockBranchId],
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
                        'branch_id' => $stockBranchId,
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
        // Strict plan binding (owner, 9 Aug 2026): Product Excel import/export
        // is a Business+ plan-card promise — same gate on template/export + import.
        if ($r = $this->planGate('excel_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');
        $existingProducts = PosProduct::where('company_id', $companyId)->orderBy('name')->get();

        // Real .xlsx (not CSV) — shopkeepers edit in Excel and upload the SAME file
        // back. The old CSV round-trip mangled long barcodes into scientific notation
        // (8.9E+12) and Excel's "save as .xlsx" default made uploads fail (Pizza Master).
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        $headers = ['Name', 'Price', 'Description', 'Category', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)', 'Tax Exempt (Yes/No)', 'Third Schedule (Yes/No)'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
        $sheet->getStyle('A1:J1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E9D5FF');
        foreach (['A' => 32, 'B' => 10, 'C' => 32, 'D' => 16, 'E' => 14, 'F' => 18, 'G' => 11, 'H' => 12, 'I' => 18, 'J' => 20] as $col => $w) {
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
                // Pad to 10 elements (9=exempt, 10=third_schedule)
                if (count($s) < 10) { $s[] = 'No'; }
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
                    !empty($p->is_third_schedule) ? 'Yes' : 'No',
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
        // A..J = Name, Price, Description, Category, SKU, Barcode, Tax %, UOM, Tax Exempt, Third Schedule.
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
        $sheet->setCellValue('J' . $rowNum, $vals[9] ?? 'No');
    }

    public function importProducts(Request $request)
    {
        // Strict plan binding (owner, 9 Aug 2026): Excel import is Business+.
        if ($r = $this->planGate('excel_enabled')) {
            return $r;
        }
        $companyId = app('currentCompanyId');

        // Subscription access gate (Task 361 review): this route deliberately has
        // no plan.limit middleware (an at-cap shop must still be able to run an
        // UPDATE-only import), so the middleware's Step-1 access check is applied
        // here instead — suspended/expired/trial-ended shops are blocked before
        // any row is written. Per-row plan cap is enforced in the loop below.
        // FAIL CLOSED: no exception swallowing — an access-evaluation failure
        // aborts the request. The only pass-through is the narrow schema-compat
        // guard for minimal test schemas without a subscriptions table.
        if (\Illuminate\Support\Facades\Schema::hasTable('subscriptions')) {
            $accessCompany = Company::find($companyId);
            if ($accessCompany) {
                $access = \App\Services\SubscriptionAccessService::hasAccess($accessCompany);
                if (!$access['allowed']) {
                    return back()->with('error', \App\Services\SubscriptionAccessService::localizedLockReason($access['reason']));
                }
            }
        }

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
        // Third Schedule column (Aug 2026): round-trip Yes/No.
        $thirdIdx = $this->findColumn($header, ['third schedule (yes/no)', 'third schedule', 'third_schedule', 'is_third_schedule', 'third']);

        // 🔒 ATOMIC QUOTA ADMISSION (Task 361 review): the whole catalog read +
        // allowance computation + row writes run in ONE transaction under a
        // company-row lock, so two simultaneous imports serialize — the second
        // recounts AFTER the first commits and can never double-spend the cap.
        DB::beginTransaction();
        try {
        Company::where('id', $companyId)->lockForUpdate()->get();

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

        // Plan product cap (Task 361): the route middleware only gates ENTRY —
        // a shop 1 under its cap could still land 5,000 rows over. Creation
        // stops at the remaining allowance; UPDATES to existing products always
        // apply. null = unlimited.
        $planRemaining = \App\Services\PlanLimitService::remainingProductAllowance((int) $companyId, 'pos');
        $planSkipped = 0;

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

            // Third Schedule cell: Yes/No (tolerant); blank = leave flag as-is.
            $thirdSchedule = null;
            if ($thirdIdx !== false) {
                $tsRaw = strtolower(trim((string) ($data[$thirdIdx] ?? '')));
                if ($tsRaw !== '') {
                    $thirdSchedule = in_array($tsRaw, ['yes', 'y', '1', 'true', 'haan', 'han'], true);
                }
            }

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

            // Third Schedule implies exempt
            if ($thirdSchedule === true) { $exempt = true; }

            if ($existing) {
                $updateData = [
                    'name' => $name,
                    'price' => $price,
                    'description' => $desc !== '' ? $desc : $existing->description,
                    'category' => $cat !== '' ? $cat : $existing->category,
                    'sku' => $sku !== null ? $sku : $existing->sku,
                    'barcode' => $barcode !== null ? $barcode : $existing->barcode,
                    'tax_rate' => ($exempt === true) ? 0 : ($tax !== null ? $tax : $existing->tax_rate),
                    'uom' => $uom !== '' ? $uom : $existing->uom,
                    'is_tax_exempt' => $exempt !== null ? $exempt : (bool) $existing->is_tax_exempt,
                ];
                if (\Illuminate\Support\Facades\Schema::hasColumn('pos_products', 'is_third_schedule')) {
                    $updateData['is_third_schedule'] = $thirdSchedule !== null ? $thirdSchedule : (bool) $existing->is_third_schedule;
                }
                $existing->update($updateData);
                $updated++;
                $product = $existing;
            } else {
                // Plan cap: stop CREATING once remaining allowance is used up
                // (updates above still apply; skipped rows counted for the flash).
                if ($planRemaining !== null && $planRemaining <= 0) {
                    $planSkipped++;
                    continue;
                }
                $createData = [
                    'company_id' => $companyId,
                    'name' => $name,
                    'price' => $price,
                    'show_on_sale' => true, // explicit — never trust the DB default (prod drift)
                    'description' => $desc !== '' ? $desc : null,
                    'category' => $cat !== '' ? $cat : null,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'tax_rate' => ($exempt === true) ? 0 : ($tax !== null ? $tax : 0),
                    'uom' => $uom !== '' ? $uom : 'NOS',
                    'is_tax_exempt' => $exempt === true,
                    'is_active' => true,
                ];
                if (\Illuminate\Support\Facades\Schema::hasColumn('pos_products', 'is_third_schedule')) {
                    $createData['is_third_schedule'] = $thirdSchedule === true;
                }
                $product = PosProduct::create($createData);
                $added++;
                if ($planRemaining !== null) { $planRemaining--; }
            }

            // Keep maps fresh so later rows in the same file match this product.
            if ($barcode !== null) $byBarcode[strtolower($barcode)] = $product;
            if ($sku !== null) $bySku[strtolower($sku)] = $product;
            $byName[strtolower($name)] = $product;
        }

        DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $parts = [];
        if ($added > 0) $parts[] = __('pos.import_new_products_added', ['count' => $added]);
        if ($updated > 0) $parts[] = __('pos.import_updated', ['count' => $updated]);
        if ($samplesSkipped > 0) $parts[] = __('pos.import_sample_rows_skipped', ['count' => $samplesSkipped]);
        if ($skipped > 0) $parts[] = __('pos.import_rows_skipped', ['count' => $skipped]);
        if ($planSkipped > 0) $parts[] = __('pos.import_plan_limit_skipped', ['count' => $planSkipped]);
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
        $isThirdSchedule = $request->has('is_third_schedule');
        // Third Schedule → always tax-free (also marks exempt)
        if ($isThirdSchedule) { $isExempt = true; }
        $data = array_merge(
            $request->only(['name', 'description', 'price', 'category', 'sku', 'barcode', 'uom']),
            [
                'cost_price' => $request->filled('cost_price') ? $request->cost_price : 0,
                'stock_quantity' => $request->filled('stock_quantity') ? (int) $request->stock_quantity : null,
                'low_stock_threshold' => $request->filled('low_stock_threshold') ? (int) $request->low_stock_threshold : ($product->low_stock_threshold ?? 10),
                // Backend hardening: exempt/third-schedule MUST force tax_rate=0
                'tax_rate' => $isExempt ? 0 : ($request->has('tax_rate') ? $request->tax_rate : $product->tax_rate),
                'is_tax_exempt' => $isExempt,
                'is_third_schedule' => $isThirdSchedule,
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

        // Per-branch stock (Task 1354): the number typed here belongs to ONE
        // shop. On the owner's all-branches view the field shows the company
        // total, which cannot be "set" onto a single branch without inventing
        // goods — so the stock edit is ignored there (the page marks it
        // read-only and points at Adjust Stock / Transfer instead).
        $stockBranchId = \App\Services\BranchStockService::viewBranchId($companyId);
        $stockEditBlocked = \App\Services\BranchStockService::viewingAllBranches($companyId);
        if ($stockEditBlocked) {
            $data['stock_quantity'] = $oldStockQty;
            $newStockQty = null;
        }

        if (
            $companyRow && $companyRow->inventory_enabled
            && $newStockQty !== null && (int) $newStockQty !== (int) ($oldStockQty ?? PHP_INT_MIN)
        ) {
            \DB::transaction(function () use ($companyId, $product, $newStockQty, $stockBranchId, &$data) {
                $branchId = \App\Services\BranchStockService::writeBranchId($companyId, $stockBranchId);
                $stockRow = \App\Models\InventoryStock::firstOrCreate(
                    ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => $branchId],
                    ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
                );
                $prevQty = (float) $stockRow->quantity;
                $setQty = (float) $newStockQty;
                if ($prevQty !== $setQty) {
                    $stockRow->update(['quantity' => $setQty]);
                    \App\Models\InventoryMovement::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'branch_id' => $branchId,
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
                // The mirror holds the COMPANY TOTAL — with branches that is the
                // sum across every shop, not the single figure just typed in.
                if ($branchId) {
                    $data['stock_quantity'] = (int) round((float) \DB::table('inventory_stocks')
                        ->where('company_id', $companyId)
                        ->where('product_id', $product->id)
                        ->sum('quantity'));
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
     * POST /pos/products/search-mode — per-company product search mode (owner,
     * 4 Aug 2026): 'prefix' (strict name-prefix + zero-result word rescue) or
     * 'any_word' (match the start of ANY word right away). Same admin gate as
     * bulkToggleSale: route sits OUTSIDE PosAdminOnly (it redirects, not 403s);
     * the controller enforces a true 403 for cashiers/confined roles.
     */
    public function productSearchMode(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || ($user->isPosCashier() ? $user->posCashierBlocked() : !$user->isPosAdmin())) {
            abort(403, 'Only POS administrators can change the product search mode.');
        }
        $request->validate(['mode' => 'required|in:prefix,any_word']);
        $company = Company::find(app('currentCompanyId'));
        // hasColumn guard (PROD schema drift): page must not 500 if the live
        // migration lagged a deploy — silently keep the default instead.
        if ($company && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_product_search_mode')) {
            $company->pos_product_search_mode = $request->input('mode');
            $company->save();
        }
        return back()->with('success', __('pos.search_mode_saved'));
    }

    /**
     * Bulk actions on selected products (company-scoped).
     * action: activate | deactivate | delete | category (with category_value).
     */
    public function bulkProductAction(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'action' => 'required|string|in:activate,deactivate,delete,category,price,price_percent,exempt_on,exempt_off,third_on,third_off',
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
            case 'third_on':
                // Third Schedule ON → also force is_tax_exempt=true (tax_rate untouched).
                // Schema guard: column may not exist yet on prod before migration runs.
                if (\Illuminate\Support\Facades\Schema::hasColumn('pos_products', 'is_third_schedule')) {
                    $query->update(['is_third_schedule' => true, 'is_tax_exempt' => true]);
                }
                $msg = __('pos.products_third_on', ['count' => $count]);
                break;
            case 'third_off':
                if (\Illuminate\Support\Facades\Schema::hasColumn('pos_products', 'is_third_schedule')) {
                    $query->update(['is_third_schedule' => false]);
                }
                $msg = __('pos.products_third_off', ['count' => $count]);
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

    /**
     * "Is customer ko handle kar liya" — clear one row off the dashboard's
     * gone-quiet card so the next silent regular moves up (owner, 23 Aug 2026).
     *
     * Nothing is deleted: only THIS silence is marked handled. If the customer
     * comes back, orders, and then goes quiet again, the alert returns on its
     * own (see PosRepeatCustomerAlert::dismiss).
     */
    public function dismissInactiveRegular(Request $request)
    {
        $user = auth('pos')->user();
        // Same audience as the card itself — cashiers never see it.
        $isAdmin = $user && in_array($user->pos_role ?? $user->role ?? '', ['pos_admin', 'pos_manager', 'company_admin'], true);
        if (!$isAdmin) {
            abort(403, 'Only POS administrators can clear customer alerts.');
        }

        $companyId = app('currentCompanyId');
        $customerId = (int) $request->input('customer_id');

        // Company scope first: an id from another shop must not even be looked up.
        $exists = PosCustomer::where('company_id', $companyId)->whereKey($customerId)->exists();
        if (!$exists) {
            return response()->json(['success' => false, 'message' => __('pos.failed_word')], 404);
        }

        if (!\App\Services\PosRepeatCustomerAlert::dismiss($companyId, $customerId, $user->id)) {
            // Already gone from the list (someone else cleared it, or the
            // customer ordered again) — the card just needs a refresh.
            return response()->json([
                'success' => true,
                'remaining' => \App\Services\PosRepeatCustomerAlert::listFor($companyId)->count(),
            ]);
        }

        return response()->json([
            'success' => true,
            'remaining' => \App\Services\PosRepeatCustomerAlert::listFor($companyId)->count(),
        ]);
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
                    // Pata ab list par nazar aata hai, is liye search bhi usay
                    // dekhti hai — gali/mohalla se customer milna chahiye.
                    ->orWhere('address', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('cnic', 'like', $like)
                    ->orWhere('ntn', 'like', $like);
            });
        }
        $totalCount = PosCustomer::where('company_id', $companyId)->count();
        // except('rows'): the live-search flag must never leak into the pager
        // links, or clicking page 2 would download the JSON payload.
        $customers = $query->orderBy('name')->paginate(100)->appends($request->except(['page', 'rows']));
        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        // Task 1161: khamosh-repeat chip — same cached service as the dashboard
        // card so the definition never drifts.
        $inactiveMap = \App\Services\PosRepeatCustomerAlert::mapFor($companyId);

        // Live search (owner request, 23 Aug 2026): the box searches the SERVER
        // as the shop types — no Enter, no Search button. Same query, same
        // rows partial; only the rows + counter + pager travel back.
        if ($request->boolean('rows')) {
            return response()->json([
                'success' => true,
                'html' => view('pos.partials.customer-rows', [
                    'customers' => $customers,
                    'isCashier' => $isCashier,
                    'inactiveMap' => $inactiveMap,
                ])->render(),
                'pagination' => $customers->hasPages() ? (string) $customers->links() : '',
                'found' => $customers->total(),
                'total' => $totalCount,
                'q' => $q,
            ]);
        }

        return view('pos.customers', compact('customers', 'isCashier', 'q', 'totalCount', 'inactiveMap'));
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
     * When the company's "customer spend persist" setting is ON (default),
     * archived local bills (day-close 'save' policy) remain visible through
     * withoutGlobalScope. Deleted local bills never return here: their snapshots
     * are amount-only customer-spend records, not purchase-history rows.
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
        $visibleSpent = (float) $transactions->sum('total_amount');
        // Deleted local bills add only to the lifetime spend number. They must
        // not inflate bill count/average or become the customer's "last order".
        $totalSpent = $visibleSpent + \App\Services\PosCustomerSpend::deletedLocalTotal((int) $companyId, $customer);
        $totalOrders = $transactions->count();
        $avgOrder = $totalOrders > 0 ? $visibleSpent / $totalOrders : 0;
        $lastOrder = $transactions->first();

        // Task 1161: khamosh-repeat chip on the header (same service as dashboard).
        $inactiveInfo = \App\Services\PosRepeatCustomerAlert::mapFor($companyId)[$customer->id] ?? null;

        return view('pos.customer-history', compact('company', 'customer', 'transactions', 'totalSpent', 'totalOrders', 'avgOrder', 'lastOrder', 'inactiveInfo'));
    }

    /**
     * One bill's line items, for the "kya order kiya tha" quick view on the
     * customer-history page (owner request, 23 Aug 2026). Saving a customer's
     * history is only useful if the shop can open a row and see the items.
     *
     * Authorization is deliberately IDENTICAL to the bill's own detail page —
     * company scope, Billing Scope stream lock and per-cashier isolation — so
     * this modal can never show a bill that transactionShow() would 403.
     */
    public function customerHistoryBill($id)
    {
        $companyId = app('currentCompanyId');
        // Archived LOCAL bills stay openable (the history lists them); archived
        // PRA bills stay hidden — same rule as transactionShow()/receipt().
        $transaction = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where(function ($q) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_archived')) {
                    $q->where('is_archived', false)
                      ->orWhereNull('is_archived')
                      ->orWhere('invoice_mode', 'local');
                }
            })
            ->with('items')
            ->findOrFail($id);

        abort_unless($this->billingScopeAllowsRow($transaction), 403);

        return response()->json([
            'success' => true,
            'invoice' => $transaction->pra_invoice_number ?: $transaction->invoice_number,
            'local_ref' => $transaction->invoice_number,
            'date' => optional($transaction->created_at)->format('d M Y, H:i'),
            'is_local' => (bool) $transaction->isLocalBill(),
            'is_return' => ($transaction->transaction_type ?? 'sale') === 'return',
            'payment' => \App\Support\PosPaymentLabels::label($transaction->payment_method),
            'items' => $transaction->items->map(fn ($i) => [
                'name' => (string) $i->item_name,
                'qty' => (float) $i->quantity,
                'price' => (float) $i->unit_price,
                'discount' => (float) ($i->item_discount_amount ?? 0),
                'total' => (float) $i->subtotal,
                'notes' => (string) ($i->special_notes ?? ''),
            ])->values(),
            'subtotal' => (float) $transaction->subtotal,
            'discount' => (float) $transaction->discount_amount,
            'tax' => (float) $transaction->tax_amount,
            'total' => (float) $transaction->total_amount,
            // Relative on purpose: an absolute https URL breaks plain-http dev.
            'url' => route('pos.transaction.show', $transaction->id, false),
        ]);
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
                    \App\Support\PosPaymentLabels::label($t->payment_method),
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
        $visibleSpent = (float) $transactions->sum('total_amount');
        $totalSpent = $visibleSpent + \App\Services\PosCustomerSpend::deletedLocalTotal((int) $companyId, $customer);
        $totalOrders = $transactions->count();
        $avgOrder = $totalOrders > 0 ? $visibleSpent / $totalOrders : 0;

        $filename = 'Customer_History_' . preg_replace('/[^A-Za-z0-9]+/', '_', $customer->name) . '_' . now()->format('Ymd') . '.pdf';
        return $this->renderReportPdf(
            'pos.customer-history-pdf',
            compact('company', 'customer', 'transactions', 'totalSpent', 'totalOrders', 'avgOrder'),
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

        // Task 1197: an isolated cashier may not lock (edit-claim) a peer's bill.
        if (!$transaction->allowedForCashierIsolationOf(auth('pos')->user())) {
            return response()->json(['success' => false, 'message' => __('pos.custom_access_denied')], 403);
        }

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

        // Task 1197: releasing a peer's edit-lock would let an isolated cashier
        // interfere with a colleague's in-progress edit — same guard as lock.
        if (!$transaction->allowedForCashierIsolationOf(auth('pos')->user())) {
            return response()->json(['success' => false, 'message' => __('pos.custom_access_denied')], 403);
        }

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

            // Third Schedule snapshot: DB is the ONLY source of truth for
            // product-backed lines — never trust the client payload, which can
            // be crafted to force 0-tax on non-Third-Schedule items.
            // For manual lines (no item_id), the flag is always false; cashiers
            // cannot self-exempt manual ad-hoc lines via this flag.
            $isThirdSchedule = false;
            if ($itemId && $itemType === 'product') {
                // Company-scoped lookup prevents cross-company flag injection
                $dbProduct = PosProduct::where('company_id', $companyId)->where('id', $itemId)->first();
                if ($dbProduct) {
                    // Schema guard: column may not exist on prod before migration
                    if (\Illuminate\Support\Facades\Schema::hasColumn('pos_products', 'is_third_schedule')) {
                        $isThirdSchedule = (bool) $dbProduct->is_third_schedule;
                    }
                    // DB lookup succeeded — do NOT fall through to client payload
                }
                // If product not found in DB (deleted/cross-company): flag stays false
            }
            // No client-payload fallback: for any product-backed line, DB wins.
            // For manual lines (itemId null), $isThirdSchedule stays false.

            // Third Schedule implies exempt (belt-and-suspenders at billing time)
            if ($isThirdSchedule) { $isExempt = true; }

            $resolved[] = [
                'type' => $itemType,
                'item_id' => $itemId,
                'name' => $itemName,
                'price' => $itemPrice,
                'quantity' => $qty,
                'lineTotal' => round($qty * $itemPrice, 2),
                'isExempt' => $isExempt,
                'isThirdSchedule' => $isThirdSchedule,
                // Task 636: same identity-autofill note discard as the waiter path —
                // a note that is EXACTLY the cashier's login email/username/name/phone
                // is browser autofill garbage, never a kitchen instruction.
                'notes' => RestaurantWaiterController::stripIdentityNote(
                    isset($item['special_notes']) ? (string) $item['special_notes'] : null,
                    auth('pos')->user()
                ),
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

    /**
     * Next FINAL (PRA-stream) serial — short "P-036" since 25 Aug 2026.
     *
     * The whole rule (short format, monotonic counter, legacy POS-YYYY-NNNNN
     * rows still reserving their numbers) lives in PosFinalSeries so this path
     * and the restaurant pay path can never drift apart.
     */
    private function generateInvoiceNumber(int $companyId): string
    {
        return \App\Services\PosFinalSeries::issueNext($companyId);
    }

    /**
     * Retail sale path — the next local bill number (L-001, L-002, …).
     *
     * The rule (exact serial grammar plus the durable company high-water mark)
     * lives in ONE place: \App\Services\PosLocalSeries.
     * The restaurant pay path and the read-only Customize POS preview call the
     * same helper, so all three can never drift apart (Task 1373).
     * issueNext() takes the FOR UPDATE lock — call inside the sale transaction.
     */
    private function generateLocalInvoiceNumber(int $companyId): string
    {
        return \App\Services\PosLocalSeries::issueNext($companyId);
    }

    public function billing()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Standalone edition retired (Jul 2026) — everyone sees the PRA POS plans.
        $plans = PosPlanComparisonService::plans();
        $currentSubscription = \App\Models\Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        // Paid feature add-ons (Aug 2026). Prices and the purchasable list come
        // from the services so this page always quotes exactly what the approval
        // will activate — no client-side arithmetic, no hand-written catalogue.
        $addonPurchasable = \App\Services\PosAddonService::purchasableCodes($company);
        $rememberedAddonSelection = session(\App\Services\PosAddonService::SIGNUP_SESSION_KEY, []);
        $rememberedAddonQuote = \App\Services\PosAddonService::quote(
            (array) ($rememberedAddonSelection['codes'] ?? []),
            // Default to the cycle the PACKAGE runs on: an add-on always expires
            // with the package, so a monthly shop must not be quoted a year.
            (string) ($rememberedAddonSelection['cycle'] ?? \App\Services\PosAddonService::cycleForCompany($company)),
            $company,
            $currentSubscription
        );
        $addonQuotes = [];
        foreach ($addonPurchasable as $addonCode) {
            foreach (\App\Services\PosAddonPricingService::CYCLES as $addonCycle) {
                $addonQuotes[$addonCode][$addonCycle] = \App\Services\PosAddonService::quote(
                    [$addonCode],
                    $addonCycle,
                    $company,
                    $currentSubscription
                );
            }
        }
        $addons = [
            'eligibility' => \App\Services\PosAddonService::purchaseEligibility($company),
            'catalog' => \App\Services\PosAddonPricingService::catalog(),
            'purchasable' => $addonPurchasable,
            'active' => \App\Services\PosAddonService::activeCodes($company),
            'pending' => \App\Services\PosAddonService::pendingCodes($company),
            'preselected' => array_values(array_intersect($rememberedAddonQuote['codes'], $addonPurchasable)),
            'preselected_cycle' => $rememberedAddonQuote['cycle'],
            'quotes' => $addonQuotes,
            // Spending the shop's money is an owner/manager decision. A cashier
            // may still SEE what is active; the POST is guarded server-side.
            'can_buy' => !(auth('pos')->user()?->isPosCashier() ?? false),
        ];
        $bank = [
            'bank_name' => \App\Models\SystemSetting::get('payment_bank_name', ''),
            'account_title' => \App\Models\SystemSetting::get('payment_account_title', ''),
            'account_number' => \App\Models\SystemSetting::get('payment_account_number', ''),
            'iban' => \App\Models\SystemSetting::get('payment_iban', ''),
            'instructions' => \App\Models\SystemSetting::get('payment_instructions', ''),
        ];

        return view('pos.billing', compact('company', 'plans', 'currentSubscription', 'addons', 'bank'));
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
                // Task 579: owner-facing CNIC — same rules the login router
                // understands (13 digits, dash-tolerant, globally unique).
                'cnic' => \App\Services\LoginIdentifierResolver::cnicRules($company->id),
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

            $request->validate($rules, \App\Services\LoginIdentifierResolver::cnicMessages());

            // NTN is submitted with EVERY PRA fiscal invoice — do not allow clearing it
            // while PRA reporting is live, or subsequent submissions would carry a null NTN.
            // (NTN is optional at registration; it only becomes mandatory once PRA is ON.)
            if ($company->praReportingActive() && $request->has('ntn') && trim((string) $request->input('ntn')) === '') {
                return back()->withInput()->with('error', __('pos.ntn_cannot_clear_pra_on'));
            }

            $data = $request->only(['name', 'owner_name', 'ntn', 'email', 'phone', 'mobile', 'address', 'city', 'business_activity', 'website']);

            // Task 579: store the CNIC as plain digits — the login routers
            // compare both raw and digit-only forms, DB convention is digits.
            // hasColumn = PROD schema-drift guard.
            if ($request->has('cnic') && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'cnic')) {
                $data['cnic'] = \App\Services\LoginIdentifierResolver::normalizeCnic($request->input('cnic'));
            }

            // Receipt display preferences (per-company, POS product scope)
            if ($request->has('receipt_prefs_submitted')) {
                $prefs = $company->invoice_display_prefs ?? [];
                // Task 769 / Task 800: merge-preserve keys owned by receipt-settings
                // (show_verify_line, show_cashier, show_business_name, show_developed_by,
                // show_tax …) — a wholesale rewrite here would silently erase them.
                // Mirror of FbrPosController::businessProfile's array_merge pattern.
                $posExisting = is_array($prefs['pos'] ?? null) ? $prefs['pos'] : [];
                $prefs['pos'] = array_merge($posExisting, [
                    'show_address' => $request->has('rp_show_address'),
                    'show_ntn' => $request->has('rp_show_ntn'),
                    'show_email' => $request->has('rp_show_email'),
                    'show_mobile' => $request->has('rp_show_mobile'),
                    'show_footer' => $request->has('rp_show_footer'),
                    'footer_text' => trim((string) $request->input('rp_footer_text', '')) ?: null,
                ]);
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
                    // Task 529: same format rule as the Team page (no spaces/@ —
                    // an email-looking username could never resolve at login).
                    'username' => \App\Services\LoginIdentifierResolver::usernameRules($user->id),
                ], [
                    ...\App\Services\LoginIdentifierResolver::usernameMessages(),
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

    // ── Day-close branch scope (Task 1360) ───────────────────────────────────
    //
    // Day close is PER BRANCH once a shop has branches: the branch shown on
    // screen is the branch whose bills get frozen, washed and archived. Before
    // this, the preview was branch-filtered but performDayClose was
    // company-wide — Gulberg's cashier previewed Rs 770 and saved Rs 1,870,
    // and that close also archived Main Shop's local bills.
    //
    // The rule is one helper pair so preview and close can never drift again:
    // dayCloseBranchId() answers "which scope", scopeToBranch() applies it.

    /**
     * The branch this day close belongs to — null = company-wide (a shop with
     * no branches, and every pre-branch day). Mirrors the reporting context so
     * the preview and the saved report always agree.
     */
    private function dayCloseBranchId(): ?int
    {
        return app(\App\Services\BranchContextService::class)->getActiveBranchId();
    }

    /**
     * "All branches" is a REPORTING view, not a place to close a day from:
     * whatever we saved there would belong to no branch while each branch's own
     * page still said "not closed". The owner must stand in a branch first.
     */
    private function dayCloseAllBranchesView(): bool
    {
        $svc = app(\App\Services\BranchContextService::class);

        return $svc->isAllBranches() && $svc->accessibleBranches()->isNotEmpty();
    }

    /**
     * Narrow a bill/rider/return query to one close scope.
     *
     * Same semantics as BranchContextService::applyToQuery — the branch's own
     * rows PLUS legacy rows stamped before branches existed (branch_id NULL),
     * which belong to whoever closes the day. Column-guarded so a box without
     * the branch migration keeps its old company-wide behaviour.
     */
    private function scopeToBranch($query, ?int $branchId, string $table = 'pos_transactions', string $column = 'branch_id')
    {
        if ($branchId && \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
            $query->where(function ($q) use ($branchId, $column) {
                $q->where($column, $branchId)->orWhereNull($column);
            });
        }

        return $query;
    }

    /**
     * The scope a STORED Z-report was frozen in — null for company-wide reports
     * (branch-less shops and every report from before Task 1360). Reading the
     * report's own stamp, never the current session: a Z-report printed months
     * later must still show the branch whose day it closed.
     */
    private function reportBranchId(PosDayCloseReport $report): ?int
    {
        return ((int) ($report->branch_id ?? 0)) ?: null;
    }

    /**
     * A branch's frozen Z-report is that branch's document: a cashier welded to
     * Gulberg must not print Main Shop's. Owners (who can stand in any branch,
     * including the company-wide view) keep full access.
     */
    private function canSeeBranchReport(PosDayCloseReport $report): bool
    {
        $branchId = $this->reportBranchId($report);
        if (!$branchId) {
            return true; // company-wide report — nothing branch-specific in it
        }
        $svc = app(\App\Services\BranchContextService::class);

        return $svc->isOwner() || $svc->canAccess($branchId);
    }

    /** Display name of a close scope ("Gulberg"), or null when company-wide. */
    private function dayCloseBranchName(?int $branchId): ?string
    {
        if (!$branchId || !\Illuminate\Support\Facades\Schema::hasTable('branches')) {
            return null;
        }

        return \App\Models\Branch::where('id', $branchId)->value('name');
    }

    public function dayCloseReport(Request $request)
    {
        // Owner rule (5 Aug 2026): Day Close is admin/manager work by DEFAULT.
        // A cashier reaches it only when the company switch (Customize) or a
        // Team Custom Access tick re-opens it — dayCloseAllowed = single
        // verdict shared with the nav and dashboard links.
        $dayCloseUser = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($dayCloseUser && !\App\Services\PosAccessService::dayCloseAllowed($dayCloseUser)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Default = the OPEN trading day (business day): after midnight, before
        // 6 AM, with yesterday still un-closed, the page must land on yesterday.
        $date = $request->get('date', \App\Services\PosBusinessDay::current($companyId));

        // Task 1360: ONE scope drives this whole page and the close it launches
        // — the branch on screen is the branch that gets closed. Company-wide
        // (null) for a branch-less shop; the owner's "all branches" reporting
        // view can preview but not close (see closeDayReport).
        $dcBranchId = $this->dayCloseBranchId();
        $dcBranchName = $this->dayCloseBranchName($dcBranchId);
        $dcAllBranches = $this->dayCloseAllBranchesView();

        $existingReport = PosDayCloseReport::where('company_id', $companyId)
            ->forBranch($dcBranchId)
            ->whereDate('report_date', $date)
            ->first();

        // Local (non-PRA) bills are excluded from the day-close view & figures —
        // visible only in the isolated Local Bills Portal (pos_role='local_viewer').
        // Billing Scope (07 Aug 2026): LOCAL-scoped staff see the LOCAL stream's
        // figures instead — mirrors the dashboard scope flip.
        $dayCloseScope = $dayCloseUser?->posBillingScope() ?? 'both';
        // Task 1186 own-bill union: a DERIVED-scope viewer's own cross-stream
        // bills join the day-close set — their drawer cash must reconcile even
        // when e.g. a reporting-ON cashier took F10 provisionals today.
        $dayCloseDerived = (bool) ($dayCloseUser?->posBillingScopeIsDerived() ?? false);
        // Task 1197: an ISOLATED cashier's day-close PREVIEW shows own-bills
        // figures only (reachable via the cashier-day-close company switch /
        // Custom Access tick). The actual close (performDayClose) and the
        // stored Z-report stay COMPANY-WIDE — this filter touches the preview
        // page figures only, never the frozen report.
        $dayCloseIso = (bool) ($dayCloseUser?->posSalesIsolated() ?? false);
        // HISTORICAL VIEW: when this page renders an already-closed report, the
        // day-close wash may have ARCHIVED the very rows the frozen figures were
        // built from — the default hide_archived scope would drop them and the
        // summary/table would look empty on a past day. Bypass it here exactly
        // like the PDF (dayCloseReportPdf) so the historical view stays truthful.
        // An OPEN/current day (no report yet) keeps the normal hide_archived
        // scope — this must never broaden ordinary open-day access. An isolated
        // cashier never sees the frozen company-wide document, so they stay on
        // the live hide_archived scope too (their preview recomputes own-bills).
        $dcHistorical = $existingReport && !$dayCloseIso;
        $transactions = PosTransaction::query()
            ->when($dcHistorical, fn ($q) => $q->withoutGlobalScope('hide_archived'))
            ->where('company_id', $companyId)
            ->where('business_date', $date)
            ->where(function ($q) use ($dayCloseScope, $dayCloseDerived, $dayCloseUser) {
                if ($dayCloseScope === 'local') {
                    $q->where('invoice_mode', 'local')->orWhere(function ($s) {
                        $s->whereNull('pra_status')->whereNull('pra_invoice_number');
                    });
                } else {
                    $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
                }
                if ($dayCloseDerived) {
                    $q->orWhere('created_by', $dayCloseUser->id);
                }
            })
            ->when($dayCloseIso, fn ($q) => $q->where('created_by', $dayCloseUser->id))
            // Multi-branch v1 (Task 1347) + per-branch close (Task 1360): the
            // preview shows the active branch's bills, and performDayClose()
            // now freezes exactly this set — both go through scopeToBranch(),
            // so the screen and the saved Z-report can never disagree again.
            // The owner's "all branches" view still previews everything.
            ->where(fn ($q) => $this->scopeToBranch($q, $dcBranchId))
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        // Return / credit-note netting (Task 570): the day-close preview nets
        // returns exactly like the stored Z-report (performDayClose) so the
        // page, PDF and thermal never disagree.
        $dcTypeReady = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type');
        $dcReturnRows = $dcTypeReady
            ? $transactions->filter(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')->values()
            : collect();
        $dcSaleRows = $dcTypeReady
            ? $transactions->reject(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')->values()
            : $transactions;

        // Cash/card/other via the ONE shared alias set (PosPaymentBuckets):
        // universal-screen 'card' is stored as 'debit_card', so matching only
        // 'card' would report Rs 0 card sales (and dump them into "Other").
        $payBuckets = PosPaymentBuckets::split($dcSaleRows);
        $refundBuckets = PosPaymentBuckets::split($dcReturnRows);

        // Returns detail (Task 678): the day-close page lists each return with
        // WHO processed it (owner audits cashier-made returns). Own query —
        // $transactions is stream-filtered for the STATS (local excluded for
        // 'both' viewers), but the audit list must show every return the
        // viewer's scope may see (streamSplit already shows both streams).
        // Netting figures above stay untouched.
        // For a CLOSED day prefer the snapshot frozen on the Z-report (Task 682):
        // the wash may have archived/deleted local return rows — the live query
        // below would silently lose them on past days' pages.
        // Task 1197: the frozen snapshot is COMPANY-WIDE — isolated cashiers
        // fall through to the live branch, which filters to their own returns.
        if ($existingReport && is_array($existingReport->returns_detail ?? null) && !$dayCloseIso) {
            $dcSnapRows = collect($existingReport->returns_detail)
                ->filter(fn ($r) => $dayCloseScope === 'both'
                    || in_array($r['stream'] ?? 'pra', ['exempt', $dayCloseScope], true))
                ->values();
            $dcReturnDetail = $dcSnapRows->map(fn ($r) => (object) [
                'id' => $r['id'] ?? null,
                'invoice_number' => $r['invoice_number'] ?? '-',
                'parent_transaction_id' => $r['parent_transaction_id'] ?? null,
                'created_at' => !empty($r['created_at']) ? \Carbon\Carbon::parse($r['created_at']) : null,
                'total_amount' => (float) ($r['amount'] ?? 0),
                'is_wastage' => (bool) ($r['is_wastage'] ?? false),
                'creator' => (object) ['name' => $r['processed_by'] ?? null],
                // The underlying row may be deleted — the page renders the
                // invoice number as plain text instead of a link.
                'snapshot' => true,
            ]);
            $dcReturnParents = $dcSnapRows
                ->filter(fn ($r) => !empty($r['parent_transaction_id']) && !empty($r['parent_invoice']))
                ->pluck('parent_invoice', 'parent_transaction_id');
        } else {
            $dcReturnDetail = $dcTypeReady
                ? PosTransaction::query()
                    // Historical view: an OLD report without a returns_detail
                    // snapshot rebuilds the audit list live — bypass hide_archived
                    // so wash-archived return rows still show on the closed day.
                    ->when($dcHistorical, fn ($q) => $q->withoutGlobalScope('hide_archived'))
                    ->where('company_id', $companyId)
                    ->where('business_date', $date)
                    ->where('transaction_type', 'return')
                    // Task 1360: audit list follows the close scope too.
                    ->where(fn ($q) => $this->scopeToBranch($q, $dcBranchId))
                    ->with('creator')
                    ->orderBy('created_at')
                    // Task 1186: user-aware guard — derived viewers keep their
                    // own cross-stream returns in the audit list.
                    // Task 1197: isolated cashier's preview audits own returns only.
                    ->get()
                    ->filter(fn ($t) => $t->allowedForBillingScopeOf($dayCloseUser)
                        && $t->allowedForCashierIsolationOf($dayCloseUser))
                    ->values()
                : collect();
            // Parent invoice numbers resolved in one query — parents can be from
            // earlier days (and may already be archived).
            $dcReturnParents = $dcReturnDetail->isNotEmpty()
                ? PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->whereIn('id', $dcReturnDetail->pluck('parent_transaction_id')->filter()->unique())
                    ->pluck('invoice_number', 'id')
                : collect();
        }

        $stats = (object) [
            'total_invoices' => $dcSaleRows->count(),
            'pra_invoices' => $dcSaleRows->where('pra_status', 'submitted')->count(),
            'local_invoices' => $dcSaleRows->whereIn('pra_status', ['local', null])->count(),
            'offline_invoices' => $dcSaleRows->where('pra_status', 'offline')->count(),
            'gross_sales' => $dcSaleRows->sum('subtotal') - $dcReturnRows->sum('subtotal'),
            'total_discount' => $dcSaleRows->sum('discount_amount') - $dcReturnRows->sum('discount_amount'),
            'net_sales' => ($dcSaleRows->sum('subtotal') - $dcReturnRows->sum('subtotal'))
                - ($dcSaleRows->sum('discount_amount') - $dcReturnRows->sum('discount_amount')),
            // PRA segregation (owner 9 Aug 2026): taxable vs exempt split —
            // same formula as the tax report (taxable = subtotal − discount −
            // exempt share; exempt_amount is post-discount, PosTaxMath).
            'taxable_value' => $dcSaleRows->sum(fn ($t) => max(0, (float) $t->subtotal - (float) $t->discount_amount - (float) ($t->exempt_amount ?? 0)))
                - $dcReturnRows->sum(fn ($t) => max(0, (float) $t->subtotal - (float) $t->discount_amount - (float) ($t->exempt_amount ?? 0))),
            'exempt_value' => $dcSaleRows->sum(fn ($t) => (float) ($t->exempt_amount ?? 0))
                - $dcReturnRows->sum(fn ($t) => (float) ($t->exempt_amount ?? 0)),
            'total_tax' => $dcSaleRows->sum('tax_amount') - $dcReturnRows->sum('tax_amount'),
            'total_amount' => $dcSaleRows->sum('total_amount') - $dcReturnRows->sum('total_amount'),
            'cash_amount' => round($payBuckets['cash'] - $refundBuckets['cash'], 2),
            'card_amount' => round($payBuckets['card'] - $refundBuckets['card'], 2),
            'other_amount' => round($payBuckets['other'] - $refundBuckets['other'], 2),
            'first_invoice' => $dcSaleRows->first(),
            'last_invoice' => $dcSaleRows->last(),
            // Returns detail line for the page/PDF/thermal.
            'returns_count' => $dcReturnRows->count(),
            'returns_amount' => round((float) $dcReturnRows->sum('total_amount'), 2),
            // Wastage line (Task 593): spoiled-goods returns — subset of the
            // returns above, shown separately so the owner sees the loss.
            'wastage_count' => $dcReturnRows->filter(fn ($t) => (bool) ($t->is_wastage ?? false))->count(),
            'wastage_amount' => round((float) $dcReturnRows->filter(fn ($t) => (bool) ($t->is_wastage ?? false))->sum('total_amount'), 2),
        ];

        // HISTORICAL VIEW: prefer the FROZEN report totals for the headline
        // summary cards on a closed day. Even with hide_archived bypassed above,
        // the wash can DELETE (not merely archive) reporting-OFF/local rows a
        // live rebuild can never recover — the frozen figures are the truth the
        // Z-report was hashed on, so the summary never looks empty on a past
        // day. Counts/tables still come from the surviving un-archived rows.
        if ($dcHistorical) {
            $stats->total_invoices = (int) $existingReport->total_invoices;
            $stats->pra_invoices = (int) $existingReport->pra_invoices;
            $stats->local_invoices = (int) $existingReport->local_invoices;
            $stats->offline_invoices = (int) $existingReport->offline_invoices;
            $stats->gross_sales = (float) $existingReport->gross_sales;
            $stats->total_discount = (float) $existingReport->total_discount;
            $stats->net_sales = (float) $existingReport->net_sales;
            $stats->total_tax = (float) $existingReport->total_tax;
            $stats->total_amount = (float) $existingReport->total_amount;
            $stats->cash_amount = (float) $existingReport->cash_amount;
            $stats->card_amount = (float) $existingReport->card_amount;
            $stats->other_amount = (float) $existingReport->other_amount;
            $stats->returns_count = (int) $existingReport->returns_count;
            $stats->returns_amount = (float) $existingReport->returns_amount;
        }

        // Cashier figures are SIGNED (Task 570): refunds net revenue/tax;
        // counts stay sales-only.
        $cashierBreakdown = $transactions->groupBy(fn($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            $sign = fn ($t) => ($t->transaction_type ?? 'sale') === 'return' ? -1 : 1;
            return (object) [
                'count' => $group->filter(fn ($t) => ($t->transaction_type ?? 'sale') !== 'return')->count(),
                'revenue' => $group->sum(fn ($t) => $sign($t) * (float) $t->total_amount),
                'tax' => $group->sum(fn ($t) => $sign($t) * (float) $t->tax_amount),
            ];
        });

        // Task 1349: counter-wise (terminal) breakdown — same signed convention.
        $terminalBreakdown = $this->buildTerminalBreakdown($transactions, $companyId);

        // Task 1197: stored Z-reports hold COMPANY-WIDE figures — an isolated
        // cashier gets no history list (the report views 403 them anyway).
        $previousReports = $dayCloseIso
            ? collect()
            // Task 1360: this branch's own history — another branch's Z-report
            // holds figures this page's viewer never saw.
            : PosDayCloseReport::where('company_id', $companyId)
                ->forBranch($dcBranchId)
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
            ->whereNull('pra_invoice_number')
            // Task 1197: isolated cashier's wash preview (localWash figures +
            // bill-by-bill list) shows own pending bills only.
            ->when($dayCloseIso, fn ($q) => $q->where('created_by', $dayCloseUser->id))
            // Task 1360: the wash preview lists what THIS close will archive —
            // performDayClose narrows to the same branch, so the list and the
            // actual wash touch identical rows.
            ->where(fn ($q) => $this->scopeToBranch($q, $dcBranchId));
        // Bill-by-bill list (Task 677): the page shows each pending bill with
        // its own action selector — fetch display columns too. Rider columns
        // are schema-guarded (prod drift self-heal convention).
        $washRiderReady = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_id');
        $washCols = ['id', 'invoice_number', 'status', 'created_at', 'business_date', 'total_amount', 'payment_method'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'customer_name')) {
            $washCols[] = 'customer_name';
        }
        if ($washRiderReady) {
            array_push($washCols, 'rider_id', 'rider_settlement_id', 'delivery_status');
        }
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
            ->get($washCols);
        $pendingFinal = $pendingBase()
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->whereNull('pra_status')
            ->get($washCols);
        // Combined per-bill rows for the close form (Task 677): kind decides the
        // selector options; unsettled rider-cash bills can never be deleted
        // (khata proof — the wash archives them regardless, so the UI says so).
        $khata = fn ($t) => $washRiderReady && $t->rider_id && $t->payment_method === 'cash'
            && !$t->rider_settlement_id && $t->delivery_status !== 'returned';
        $washBills = $pendingProv->map(fn ($t) => (object) [
            'id' => $t->id,
            'kind' => 'provisional',
            'invoice_number' => $t->invoice_number,
            'customer_name' => $t->customer_name ?? null,
            'total_amount' => (float) $t->total_amount,
            'business_date' => $t->business_date,
            'created_at' => $t->created_at,
            'is_draft' => ($t->status ?? null) === 'draft',
            'khata' => $khata($t),
        ])->concat($pendingFinal->map(fn ($t) => (object) [
            'id' => $t->id,
            'kind' => 'final_local',
            'invoice_number' => $t->invoice_number,
            'customer_name' => $t->customer_name ?? null,
            'total_amount' => (float) $t->total_amount,
            'business_date' => $t->business_date,
            'created_at' => $t->created_at,
            'is_draft' => false,
            'khata' => $khata($t),
        ]))->sortBy([['business_date', 'asc'], ['id', 'asc']])->values();
        $localWash = (object) [
            'prov_count' => $pendingProv->count(),
            'prov_amount' => (float) $pendingProv->sum('total_amount'),
            'prov_backlog' => $pendingProv->filter(fn ($t) => $t->business_date && $t->business_date < $date)->count(),
            'final_count' => $pendingFinal->count(),
            'final_amount' => (float) $pendingFinal->sum('total_amount'),
            'final_backlog' => $pendingFinal->filter(fn ($t) => $t->business_date && $t->business_date < $date)->count(),
        ];

        $analytics = $this->buildDayCloseAnalytics($companyId, $date, $transactions, $company, $dcBranchId);

        // Per-stream split (Task 660): PRA vs Local vs Exempt boxes. For a
        // CLOSED day prefer the figures frozen on the report (the wash may
        // have deleted reporting-OFF finals — live recompute would undercount);
        // otherwise compute live from today's set.
        // Task 1197: an isolated cashier NEVER sees the frozen company-wide
        // summary — their preview recomputes live from the own-bills set.
        $streamSplit = ($existingReport && is_array($existingReport->stream_summary ?? null) && !$dayCloseIso)
            ? $existingReport->stream_summary
            : $this->buildDayCloseStreamSplit($this->withLocalStreamRows($transactions, $companyId, $date, $dayCloseIso ? (int) $dayCloseUser->id : null, $dcBranchId));

        // Task 705: Z/X display mode-gating — normal mode = PRA section only;
        // khufia local-check mode ON = Local stream figures too. LOCAL-scoped
        // viewers always see their own local world (scope forcing intact).
        $showLocalStream = (bool) session('pos_local_check') || $dayCloseScope === 'local';

        // Delivery Riders (Jul 2026): live rider cash figures for the recon preview
        // (unsettled rider cash is OUT of the drawer; earlier-day settlements are IN).
        $riderFigures = $this->buildRiderDayFigures($companyId, $date, $dayCloseIso ? (int) $dayCloseUser->id : null, $dcBranchId);

        // Opening Cash Balance (Jul 2026): day-start entry auto-fills the
        // reconciliation's opening float for this date. Task 1360: each branch
        // counts its own drawer, so the float follows the close scope.
        // Task 1375: with counters the shop's float is the SUM of the counters'
        // floats — $dayOpening stays the shop-drawer row (the "recorded at day
        // start" hint), $dayOpeningTotal is what the recon prefills with.
        $dayOpening = \App\Models\PosDayOpening::forDate($companyId, $date, $dcBranchId);
        $dayOpeningTotal = \App\Models\PosDayOpening::totalForDate($companyId, $date, $dcBranchId);

        // Task 1375: per-counter cash drawers. For a CLOSED day prefer the
        // snapshot frozen on the Z-report (same reason as stream_summary: the
        // wash can delete rows a live rebuild would miss); otherwise build live.
        // Counter-less shops get an empty collection and the card never renders.
        $counterCash = ($existingReport && is_array($existingReport->counter_summary ?? null) && !$dayCloseIso)
            ? collect($existingReport->counter_summary)
            : \App\Services\PosCounterDrawer::rows(
                $companyId, $dcBranchId, $date, $transactions, $riderFigures,
                $dayCloseIso ? (int) $dayCloseUser->id : null
            );
        $counterCashTotals = $counterCash->isEmpty() ? null : \App\Services\PosCounterDrawer::totals($counterCash);
        // Live actions (close/reopen a counter) only make sense on the OPEN
        // business day of a single branch — never on history, never on the
        // merged all-branches view.
        $counterCashLive = !$existingReport && !$dcAllBranches
            && $date === \App\Services\PosBusinessDay::current($companyId);

        // Day-close warning (ZFC 28 Jul 2026, detailed 3 Aug 2026): open held
        // orders / occupied tables must be surfaced BEFORE closing — otherwise
        // they dangle into tomorrow (ZFC: 5 tables sat occupied for 2 days and
        // nobody noticed). Shows table numbers + amounts, restaurant-mode only.
        $openHeld = $this->openHeldOrdersSummary($companyId, $company);
        $openOrders = $openHeld->count;
        $occupiedTables = $openHeld->tables;

        // Stranded-day banner (Task 455): if the 6 AM auto-close was skipped
        // (open orders) or auto-close is off and nobody closed manually, prior
        // business days sit open with no visible trace. Surface every un-closed
        // prior trading day so staff close it BEFORE more bills pile onto today.
        // Same detection as pos:auto-dayclose: keyed by business_date, archived
        // rows included, closed = a PosDayCloseReport row exists for that date.
        $unclosedPriorDays = $this->unclosedPriorBusinessDays($companyId, $date, false, $dcBranchId);

        // Pending checklist (Task 661, ZFC): undispatched delivery bills HARD-BLOCK
        // the close (ZFC closed a day while delivery orders never left the shop);
        // rider unsettled cash is a WARNING only (khata legitimately carries).
        $pendingDeliveries = $this->undispatchedDeliverySummary($companyId, $company, $date);
        // The blocker list stays company-wide, but only rows this branch owns
        // may be CLEARED from here — the page must not offer a button that
        // would mutate another branch's live delivery (branch-less shops: all).
        foreach ($pendingDeliveries->rows as $dcRow) {
            $dcRow->clearable = $this->dayCloseRowInScope($dcRow, $dcBranchId);
        }
        $dcBlockersAllClearable = $pendingDeliveries->rows->every(fn ($r) => $r->clearable);

        // Task 1197: $dcIso gates the blade's COMPANY-WIDE Z sections (frozen
        // cash recon, local/rider summaries, Z print links) for isolated cashiers.
        $dcIso = $dayCloseIso;

        // Parked bills (owner, 23 Aug 2026): unfinished carts a counter set
        // aside. They are NOT sales, so they never touch the day's figures —
        // the page just reminds the shop they are still sitting there.
        $parkedBills = \App\Services\PosParkedBills::count(\App\Services\PosParkedBills::PRA_TABLE, $companyId);

         $summaryReport = $this->dayCloseReportMode($request) === 'summary';
         $summary = $this->buildDayCloseSummary(
             $stats,
             $streamSplit,
             $showLocalStream,
             $existingReport,
             $counterCashTotals,
             $this->buildDayCloseSummaryPaymentSplit($dcSaleRows, $dcReturnRows),
             $existingReport !== null
         );
         // X/open-day reconciliation has an opening float but no counted cash
         // yet. Mirror the detailed page's current-day opening source without
         // manufacturing a close or a variance.
         if (!$existingReport && $dayOpeningTotal !== null) {
             $summary['cash_recon'] = [
                 'visible' => true,
                 'opening' => (float) $dayOpeningTotal,
                 'expected' => round((float) $dayOpeningTotal + (float) ($summary['payments']['cash'] ?? 0), 2),
                 'counted' => null,
                 'variance' => null,
             ];
         }

         if ($summaryReport) {
             return view('pos.day-close-summary', compact('company', 'date', 'stats', 'existingReport', 'streamSplit', 'showLocalStream', 'dcIso', 'dcBranchName', 'dcAllBranches', 'counterCashTotals', 'summary'));
         }

         return view('pos.day-close', compact('parkedBills', 'company', 'date', 'stats', 'existingReport', 'cashierBreakdown', 'terminalBreakdown', 'previousReports', 'transactions', 'localWash', 'washBills', 'analytics', 'riderFigures', 'dayOpening', 'openOrders', 'occupiedTables', 'openHeld', 'unclosedPriorDays', 'streamSplit', 'showLocalStream', 'pendingDeliveries', 'dcBlockersAllClearable', 'dcReturnDetail', 'dcReturnParents', 'dcIso', 'dcBranchName', 'dcAllBranches', 'dayOpeningTotal', 'counterCash', 'counterCashTotals', 'counterCashLive'));
    }

     /**
      * Report mode is selected only by the dedicated route defaults (or the
      * already validated route value). Unknown values fail closed to detailed.
      */
     private function dayCloseReportMode(Request $request): string
     {
         $mode = $request->route('report_mode') ?? $request->query('report_mode', 'detailed');

         return in_array($mode, ['detailed', 'summary'], true) ? $mode : 'detailed';
     }

     /**
      * Compact report figures are a view over the already-built day-close
      * figures. This intentionally does not query or recalculate any sales.
      * Missing fields are normal on old Z-report rows.
      */
     private function buildDayCloseSummary($source, array $streamSplit, bool $showLocalStream, ?PosDayCloseReport $report = null, ?array $counterCashTotals = null, ?array $fallbackPayments = null, bool $preferStoredPayments = false): array
     {
         $payments = $preferStoredPayments && is_array($streamSplit['summary_payments'] ?? null)
             ? $streamSplit['summary_payments']
             : ($fallbackPayments ?: (is_array($streamSplit['summary_payments'] ?? null)
                 ? $streamSplit['summary_payments']
                 : [
                 'cash' => (float) ($source->cash_amount ?? 0),
                 'card' => (float) ($source->card_amount ?? 0),
                 'online' => 0.0,
                 'other' => (float) ($source->other_amount ?? 0),
                 ]));

         $cashRecon = [
             'visible' => ($source->opening_float ?? null) !== null
                 || ($source->counted_cash ?? null) !== null
                 || ($source->expected_cash ?? null) !== null,
             'opening' => $source->opening_float ?? null,
             'expected' => $source->expected_cash ?? null,
             'counted' => $source->counted_cash ?? null,
             'variance' => $source->cash_variance ?? null,
         ];

         return [
             'invoice_count' => (int) ($source->total_invoices ?? 0),
             'gross_sales' => (float) ($source->gross_sales ?? 0),
             'discount' => (float) ($source->total_discount ?? 0),
             'net_sales' => (float) ($source->net_sales ?? 0),
             'tax' => (float) ($source->total_tax ?? 0),
             'total' => (float) ($source->total_amount ?? 0),
             'returns_count' => (int) ($source->returns_count ?? 0),
             'returns_amount' => (float) ($source->returns_amount ?? 0),
             'pra_invoices' => (int) ($source->pra_invoices ?? 0),
             'local_invoices' => (int) ($source->local_invoices ?? 0),
             'offline_invoices' => (int) ($source->offline_invoices ?? 0),
             'payments' => $payments,
             'pra' => $streamSplit['pra'] ?? ['count' => 0, 'sales' => 0, 'tax' => 0],
             'local' => $streamSplit['local'] ?? ['count' => 0, 'sales' => 0, 'tax' => 0],
             'cash_recon' => $cashRecon,
             'counter_totals' => $counterCashTotals,
             'show_local' => $showLocalStream,
             'is_frozen' => $report !== null,
         ];
     }

    /**
     * Open held-order summary for day-close warnings (ZFC 3 Aug 2026): how many
     * orders are still un-settled, which table numbers, and how much money is
     * sitting on them. Restaurant-mode companies only (plan-allowed + toggled on);
     * everyone else gets a zeroed summary so the warning block never renders.
     * Also the authority for the MANUAL day-close hard block (owner rule
     * 10 Aug 2026): closeDayReport refuses while count > 0. The 6 AM AUTO
     * close SKIPS the day entirely and logs a warning (skip_alert policy,
     * owner decision 10 Aug 2026) — it no longer closes past open orders.
     */
    private function openHeldOrdersSummary(int $companyId, ?Company $company): object
    {
        $empty = (object) ['count' => 0, 'tables' => 0, 'tableNumbers' => '', 'amount' => 0.0, 'noTableCount' => 0, 'rows' => collect()];
        // Cheap column check FIRST: restaurantAllowed() hits subscriptions —
        // non-restaurant companies (the vast majority) must short-circuit
        // before any plan lookup (also keeps minimal-schema tests green).
        $restaurantEnabled = $company
            && (bool) ($company->restaurant_mode ?? false)
            && \App\Services\PosFeatureService::restaurantAllowed($company);
        if (! $restaurantEnabled || ! \Illuminate\Support\Facades\Schema::hasTable('restaurant_orders')) {
            return $empty;
        }

        // Same "open" definition as the TABLE board: held/preparing/ready —
        // anything not completed/cancelled. Item-less shells (created then
        // abandoned before adding anything) carry no money and no KOT — skip.
        $orders = \App\Models\RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->whereHas('items')
            ->with('table:id,table_number')
            ->orderBy('created_at')
            ->get(['id', 'table_id', 'total_amount', 'order_number', 'order_type', 'customer_name', 'created_at']);

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
            // Owner (23 Aug 2026, Frost and Brew): the checklist has to NAME the
            // orders that block the close — a counter/takeaway order owns no
            // table tile, so "1 open order" alone left the shop hunting for
            // something it could not see. Rows drive the clear-it-here list.
            'rows' => $orders->map(fn ($o) => (object) [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'table_number' => $o->table->table_number ?? null,
                'order_type' => $o->order_type,
                'customer_name' => $o->customer_name,
                'total_amount' => (float) $o->total_amount,
                'created_at' => $o->created_at,
            ])->values(),
        ];
    }

    /**
     * Undispatched delivery bills for the day-close pending checklist (Task 661,
     * ZFC waqia): completed delivery bills that are sitting WITH A RIDER —
     * assigned to him but never dispatched. These HARD-BLOCK the manual close
     * and make the 6 AM auto-close SKIP the day (same policy as open restaurant
     * orders), because the shop's goods and cash are out with a named person.
     *
     * A company chooses how an unassigned bill behaves: the default means the
     * shop handed it over itself, so it does not block; a stricter company can
     * require every fresh delivery to be assigned before a close. The choice
     * applies identically to manual, bulk, recovery and hourly auto-close.
     *
     * 'dispatched' does NOT block either — the rider has the order; its cash is
     * covered by the khata figures. Rider unsettled cash NEVER blocks (khata
     * legitimately carries to the next day) — it rides along here as
     * warning-only context.
     * Feature-gated (riders plan gate + Delivery toggle, mirrors
     * PosRiderController::deliveryGate) and schema-guarded: non-rider shops get
     * a zeroed summary and are never blocked by this check.
     * PUBLIC: AutoCloseDayPos calls it for the skip decision.
     */
    public function undispatchedDeliverySummary(int $companyId, ?Company $company, string $date): object
    {
        $empty = (object) ['active' => false, 'count' => 0, 'amount' => 0.0, 'assigned' => 0, 'unassigned' => 0, 'khata_count' => 0, 'khata_amount' => 0.0, 'rows' => collect()];
        try {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_id')
                || ! \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'delivery_status')) {
                return $empty;
            }
            $company = $company ?: Company::find($companyId);
            if (! $company
                || ! \App\Services\PosFeatureService::planAllows($company, 'riders_enabled')
                || empty(\App\Services\PosFeatureService::forCompany($company)->delivery)) {
                return $empty;
            }

            $hasBizDate = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'business_date');
            $hasType = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type');
            $unassignedBlocks = \App\Services\PosDayCloseDeliveryPolicy::unassignedBlocks($company);
            // Default scope (hide_archived) applies: an archived bill is out of
            // the operational stream and must not block a close.
            $rows = PosTransaction::where('company_id', $companyId)
                ->where('status', 'completed')
                ->when($hasBizDate,
                    fn ($q) => $q->where('business_date', '<=', $date),
                    fn ($q) => $q->whereDate('created_at', '<=', $date))
                ->when($hasType, fn ($q) => $q->where(function ($w) {
                    $w->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
                }))
                ->where(function ($q) use ($unassignedBlocks) {
                    // Assigned to a rider but never handed over (dispatch pending).
                    $q->where(function ($assigned) {
                        $assigned->whereNotNull('rider_id')
                            ->where('delivery_status', 'assigned')
                            ->whereNull('rider_settlement_id');
                    });

                    // Strict companies require every actually unassigned
                    // delivery to be handled before the trading day can close.
                    if ($unassignedBlocks) {
                        $q->orWhere(function ($unassigned) {
                            $unassigned->whereNull('rider_id')
                                ->whereNull('delivery_status')
                                ->whereNull('rider_settlement_id')
                                ->where('order_type', 'delivery');
                        });
                    }
                })
                ->with('rider:id,name')
                ->orderBy('created_at')
                ->get(array_merge(
                    ['id', 'rider_id', 'total_amount', 'invoice_number', 'pra_invoice_number', 'delivery_status', 'customer_name', 'created_at'],
                    // Which branch the bill belongs to — the blocker itself stays
                    // company-wide (unchanged), but the CLEAR must never mutate
                    // another branch's live delivery from this branch's close.
                    \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'branch_id') ? ['branch_id'] : []
                ));

            // Rider unsettled cash khata — warning-only context (whole open
            // khata, archived included: same scope as the rider settle path).
            // Own try/catch: a khata-side failure must never zero the BLOCKER
            // count above (warning data is expendable, the block is not).
            $khataCount = 0;
            $khataAmount = 0.0;
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('pos_riders')) {
                $khata = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->whereNotNull('rider_id')
                    ->where('payment_method', 'cash')
                    ->whereNull('rider_settlement_id')
                    ->where(function ($q) {
                        $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
                    })
                    ->selectRaw('COUNT(*) as c, COALESCE(SUM(' . \App\Models\PosRider::remainingExpr('pos_transactions') . '),0) as amt')
                    ->first();
                    $khataCount = (int) ($khata->c ?? 0);
                    $khataAmount = round((float) ($khata->amt ?? 0), 2);
                }
            } catch (\Throwable $e) {
                // warning-only figures stay zero
            }

            return (object) [
                'active' => true, // delivery feature live — checklist rows render
                'count' => $rows->count(),
                'amount' => round((float) $rows->sum('total_amount'), 2),
                'assigned' => $rows->filter(fn ($t) => $t->rider_id)->count(),
                'unassigned' => $rows->filter(fn ($t) => ! $t->rider_id)->count(),
                'khata_count' => $khataCount,
                'khata_amount' => $khataAmount,
                // Same reason as the open-orders rows: the checklist names each
                // blocking bill and closes it in place (owner, 23 Aug 2026).
                'rows' => $rows->map(fn ($t) => (object) [
                    'id' => $t->id,
                    // The SHOP-facing serial (POS-2026-000NN / L-NNN) — a PRA
                    // USIN means nothing to the counter staff looking for this
                    // bill on their board. Fiscal number rides along separately.
                    'invoice_number' => $t->invoice_number ?: ($t->pra_invoice_number ?: ('#' . $t->id)),
                    'fiscal_number' => $t->pra_invoice_number,
                    'branch_id' => $t->branch_id ?? null,
                    'rider_name' => $t->rider->name ?? null,
                    'delivery_status' => $t->delivery_status,
                    'customer_name' => $t->customer_name,
                    'total_amount' => (float) $t->total_amount,
                    'created_at' => $t->created_at,
                ])->values(),
            ];
        } catch (\Throwable $e) {
            // Checklist must never brick day-close on prod schema drift — fail
            // open (empty summary) exactly like the rider figures helper.
            return $empty;
        }
    }

    /**
     * Cancel ONE open restaurant order straight from the Day Close checklist
     * (owner, 23 Aug 2026 — "day close kyun nahi ho raha, delete kyun nahin").
     *
     * A counter/takeaway order owns no table tile, so on a tables shop the only
     * way to it was the sale screen's TABLE board → amber chip → menu. Shops
     * never found that, and the order blocked the close for days. The page that
     * REFUSES the close can now clear its own blocker.
     *
     * Authority is unchanged: the page gate (dayCloseAllowed) plus the SAME
     * cancel verdict the board uses (PosAccessService::orderCancelAllowed), and
     * the cancel itself runs through RestaurantPosController::deleteOrder so the
     * void KOT, table release, stock and audit log stay byte-identical.
     */
    public function dayCloseCancelOrder(Request $request, $orderId)
    {
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($user && !\App\Services\PosAccessService::dayCloseAllowed($user)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        if (!\App\Services\PosAccessService::orderCancelAllowed($user)) {
            return back()->with('error', __('pos.order_cancel_not_allowed'));
        }

        $response = app(\App\Http\Controllers\RestaurantPosController::class)->deleteOrder($request, $orderId);
        $data = ($response instanceof \Illuminate\Http\JsonResponse) ? (array) $response->getData(true) : [];

        if (! empty($data['success'])) {
            return back()->with('success', __('pos.dc_order_cancelled_ok'));
        }

        return back()->with('error', $data['message'] ?? __('pos.madadgar_err_generic'));
    }

    /**
     * Mark ONE blocking delivery bill as delivered, from the Day Close page.
     *
     * Why this does NOT reuse the deliveries board endpoint (Frost and Brew,
     * 23 Aug 2026 — the close was stuck for days): the board applies the STAFF
     * STREAM LOCK (local vs PRA, welded to the user's reporting flag). This
     * shop's owner runs reporting OFF, so his stream is 'local' — the two
     * PRA-stream delivery bills were invisible on his board AND a direct POST
     * was refused by streamScopeAllowsTxn. Meanwhile the close blocker counts
     * BOTH streams, so those bills could never be cleared by anyone: a
     * permanent dead end, not a user mistake.
     *
     * A day close is a company-level act that covers both streams by
     * definition, so its own cure must be stream-agnostic. Everything else
     * stays as strict as the board: company scope, the blocker shape only
     * (never a settled or already-closed bill), and the same stamps.
     */
    public function dayCloseMarkDelivered(Request $request, $txnId)
    {
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($user && !\App\Services\PosAccessService::dayCloseAllowed($user)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        $companyId = (int) app('currentCompanyId');
        // The ONLY bills this endpoint may touch are the ones currently
        // blocking the close — the same list the page just rendered. Deriving
        // them from the blocker query itself (instead of re-stating its rules)
        // means an archived bill, a bill older than the blocker's window, a
        // return, or a settled one can never be stamped through a crafted POST.
        $blockers = $this->dayCloseBlockingDeliveryIds($companyId);

        return $this->dayCloseDeliverBill($companyId, (int) $txnId, $blockers)
            ? back()->with('success', __('pos.dc_delivered_ok'))
            : back()->with('error', __('pos.dc_delivered_failed'));
    }

    /**
     * Ids of the delivery bills that are blocking the close RIGHT NOW, straight
     * from the blocker summary (single source of truth for the predicate).
     */
    private function dayCloseBlockingDeliveryIds(int $companyId): array
    {
        $company = Company::find($companyId);
        $date = \App\Services\PosBusinessDay::current($companyId);
        $branchId = $this->dayCloseBranchId();

        return $this->undispatchedDeliverySummary($companyId, $company, $date)
            ->rows
            ->filter(fn ($r) => $this->dayCloseRowInScope($r, $branchId))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * May THIS close touch that row? Same rule as scopeToBranch(): the branch's
     * own rows plus legacy rows stamped before branches existed. A branch-less
     * shop (the overwhelming majority) has no active branch, so everything is
     * in scope — but on a multi-branch shop the day close must never mark
     * ANOTHER branch's live delivery as delivered. That bill is a real order in
     * somebody else's shift; it has to be cleared where it lives.
     */
    private function dayCloseRowInScope($row, ?int $branchId): bool
    {
        if (! $branchId) {
            return true;
        }
        $rowBranch = $row->branch_id ?? null;

        return $rowBranch === null || (int) $rowBranch === $branchId;
    }

    /**
     * Low-level "this delivery bill is no longer pending" write — shared by the
     * single-row button and the one-click clear. $blockerIds is the authoritative
     * allow-list; the shape checks below are belt-and-braces on top of it.
     * Returns false when the bill is not (or is no longer) a blocker; callers
     * surface that to the user.
     */
    private function dayCloseDeliverBill(int $companyId, int $txnId, array $blockerIds): bool
    {
        try {
            if (! in_array($txnId, $blockerIds, true)) {
                return false;
            }
            if (! \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'delivery_status')) {
                return false;
            }
            $txn = PosTransaction::where('company_id', $companyId)
                ->where('is_archived', false)
                ->find($txnId);
            if (! $txn || $txn->status !== 'completed' || $txn->order_type !== 'delivery' || $txn->rider_settlement_id) {
                return false;
            }
            $company = Company::find($companyId);
            $isAssigned = $txn->rider_id !== null && $txn->delivery_status === 'assigned';
            $isUnassigned = $txn->rider_id === null && $txn->delivery_status === null
                && \App\Services\PosDayCloseDeliveryPolicy::unassignedBlocks($company);
            // Keep the one-click cure exactly as narrow as the blocker query.
            if (! $isAssigned && ! $isUnassigned) {
                return false;
            }

            $upd = ['delivery_status' => 'delivered'];
            if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'delivered_at')) {
                $upd['delivered_at'] = now();
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'delivered_by')) {
                $upd['delivered_by'] = \Illuminate\Support\Facades\Auth::guard('pos')->id();
            }
            $txn->update($upd);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Clear EVERY day-close blocker in one server pass (owner, 23 Aug 2026:
     * "idhar se udhar click click na karte rahein").
     *
     * The close button itself carries this — one click cancels the stale open
     * orders, closes the pending delivery bills and then closes the day, all in
     * the same request. The per-row buttons stay for anyone who wants to clear
     * them one by one.
     *
     * Cancelling orders needs the board's own admin/manager verdict; if the
     * closer lacks it we refuse the WHOLE clear rather than half-clearing and
     * failing at the guard two lines later.
     */
    private function clearDayCloseBlockers(int $companyId, ?Company $company, string $date, $user, Request $request, ?int $branchId = null): array
    {
        $cancelled = 0;
        $delivered = 0;
        $failed = [];

        // Branch safety FIRST, before anything is destroyed: a delivery bill
        // belonging to another branch is a live order in someone else's shift.
        // We refuse the whole clear (touching nothing) and name the bills, so
        // they get closed at the branch that owns them.
        $pendingRows = $this->undispatchedDeliverySummary($companyId, $company, $date)->rows;
        $outOfScope = $pendingRows->reject(fn ($r) => $this->dayCloseRowInScope($r, $branchId));
        if ($outOfScope->isNotEmpty()) {
            return [
                'ok' => false,
                'message' => __('pos.dc_clear_other_branch', ['items' => $outOfScope->pluck('invoice_number')->take(5)->implode(', ')]),
                'note' => '',
            ];
        }

        $open = $this->openHeldOrdersSummary($companyId, $company);
        if ($open->count > 0) {
            if (! \App\Services\PosAccessService::orderCancelAllowed($user)) {
                return ['ok' => false, 'message' => __('pos.dc_clear_needs_admin'), 'note' => ''];
            }
            foreach ($open->rows as $row) {
                $resp = app(\App\Http\Controllers\RestaurantPosController::class)->deleteOrder($request, $row->id);
                $data = ($resp instanceof \Illuminate\Http\JsonResponse) ? (array) $resp->getData(true) : [];
                if (! empty($data['success'])) {
                    $cancelled++;
                } else {
                    $failed[] = $row->order_number;
                }
            }
        }

        // Mirrors the blocker set EXACTLY: whatever refuses the close is what
        // gets cleared — never one row more (and never out of branch scope,
        // which the guard above already proved).
        $blockerIds = $pendingRows->pluck('id')->map(fn ($i) => (int) $i)->all();
        foreach ($pendingRows as $row) {
            if ($this->dayCloseDeliverBill($companyId, (int) $row->id, $blockerIds)) {
                $delivered++;
            } else {
                $failed[] = $row->invoice_number;
            }
        }

        if (! empty($failed)) {
            return [
                'ok' => false,
                'message' => __('pos.dc_clear_partial', ['items' => implode(', ', array_slice($failed, 0, 5))]),
                'note' => '',
            ];
        }

        return [
            'ok' => true,
            'message' => '',
            'note' => __('pos.dc_cleared_note', ['orders' => $cancelled, 'bills' => $delivered]),
        ];
    }

    public function closeDayReport(Request $request)
    {
        // Owner rule (5 Aug 2026): cashier day-close only via company switch / Custom Access tick.
        $dayCloseUser = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($dayCloseUser && !\App\Services\PosAccessService::dayCloseAllowed($dayCloseUser)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        // Default = the OPEN trading day (business day) — same rule as the page.
        $date = $request->input('date', \App\Services\PosBusinessDay::current($companyId));

        // Task 1360: close the branch that was previewed. "All branches" is a
        // reporting view — a close launched from there would belong to no
        // branch while every branch's own page still said "not closed", which
        // is the half-branch-aware mess this task removes.
        if ($this->dayCloseAllBranchesView()) {
            return back()->with('error', __('pos.dayclose_pick_branch'));
        }
        $dcBranchId = $this->dayCloseBranchId();

        // HARD BLOCK (owner rule 10 Aug 2026): while ANY restaurant order is still
        // open (held/preparing/ready with items), manual day-close must refuse —
        // no "close anyway" escape hatch. Un-finalized orders can never be
        // finalized after close. Defense in depth: the page hides the close
        // button too, but the endpoint is the authority. The 6 AM AUTO close
        // (AutoCloseDayPos) applies the skip_alert policy (owner 10 Aug 2026):
        // it SKIPS the day entirely and logs a warning — staff must settle
        // orders and close manually; the auto-close retries on the next run.
        //
        // ONE-CLICK CLEAR: the close button may carry `clear_blockers` — the
        // closer confirmed a dialog naming every order and bill about to be
        // cleared. NOTHING is cleared at this point: destroying a blocker and
        // then failing on a later guard (bad cash figure, cashier override,
        // report already exists) would leave the shop worse off than before.
        // The clear runs further down — last, once every other precondition has
        // passed — and these two hard blocks are re-asserted right after it.
        $wantsClear = $request->boolean('clear_blockers');
        $clearedNote = '';

        if (! $wantsClear) {
            $openAtClose = $this->openHeldOrdersSummary($companyId, $company);
            if ($openAtClose->count > 0) {
                return back()->with('error', __('pos.dayclose_blocked_open_orders', [
                    'count' => $openAtClose->count,
                    'tables' => $openAtClose->tableNumbers !== '' ? ' (' . __('pos.dc_open_tables_list', ['tables' => $openAtClose->tableNumbers]) . ')' : '',
                ]));
            }

            // HARD BLOCK #2 (Task 661, ZFC waqia): undispatched delivery bills —
            // assigned-but-not-dispatched or fresh unassigned delivery bills — also
            // refuse the close. The day is not settled while delivery orders never
            // left the shop. Rider unsettled cash (khata) deliberately does NOT
            // block — it carries to the next day (warning on the page only).
            $pendingDel = $this->undispatchedDeliverySummary($companyId, $company, $date);
            if ($pendingDel->count > 0) {
                return back()->with('error', __('pos.dayclose_blocked_undispatched', ['count' => $pendingDel->count]));
            }
        }

        // Local-bill wash at day-close now follows the STANDING company policy set by
        // an admin in Customize POS → Local Billing (save=archive | delete, per bill
        // kind). Cashiers closing the day merely trigger that admin decision — no
        // per-close purge checkbox / cashier authority question anymore.
        // Cash reconciliation (optional): opening float + physically-counted cash.
        $request->validate([
            'opening_float' => 'nullable|numeric|min:0|max:99999999',
            'counted_cash' => 'nullable|numeric|min:0|max:99999999',
            // Per-close wash override (Task 661): same vocabulary as the
            // standing policy; 'standing' = no override.
            'wash_override' => 'nullable|in:standing,finalize,save,delete',
            // Bill-by-bill choice (Task 677, owner-approved 14 Aug 2026): each
            // pending local/provisional bill may carry its OWN action for THIS
            // close. 'standing' = follow the all-box / standing policy.
            'bill_actions' => 'nullable|array',
            'bill_actions.*' => 'nullable|in:standing,finalize,save,delete',
        ]);
        // Per-close action override (Task 661, owner's 3-option choice): an
        // admin/manager may, for THIS close only, force pending local bills to
        // PRA-finalize / stay Local (archive) / delete — the standing Customize
        // policy stays untouched. Cashiers never see the dropdown, and a crafted
        // value from a cashier is refused outright (settings authority stays
        // admin-only — same rule as every /pos/settings POST).
        $washOverride = $request->input('wash_override');
        // Bill-by-bill map (Task 677): sanitize to int-id => action, dropping
        // 'standing' (= no deviation). Ids never in the wash selectors are
        // simply ignored downstream (queries stay company-scoped — a crafted
        // foreign id can never touch another company's bills).
        $billActions = [];
        foreach ((array) $request->input('bill_actions', []) as $bid => $act) {
            if (in_array($act, ['finalize', 'save', 'delete'], true) && (int) $bid > 0) {
                $billActions[(int) $bid] = $act;
            }
        }
        $actionOverride = null;
        if (($washOverride && $washOverride !== 'standing') || !empty($billActions)) {
            // isPosCashier (role), NOT posCashierBlocked (path access): a
            // cashier granted day-close custom access may CLOSE the day but
            // still must not override the owner's standing wash policy —
            // neither via the all-box nor via crafted per-bill actions.
            if ($user && $user->isPosCashier()) {
                return back()->with('error', __('pos.only_admin_change_setting'));
            }
            $actionOverride = [
                'provisional' => ($washOverride && $washOverride !== 'standing') ? $washOverride : null,
                // Reporting-OFF finals cannot be "finalized" (they already are
                // final); they follow the override only for save/delete and
                // keep the standing policy otherwise.
                'final_local' => in_array($washOverride, ['save', 'delete'], true) ? $washOverride : null,
                // Per-bill deviations beat the all-box/standing action for
                // exactly the bills named (Task 677).
                'bills' => $billActions,
            ];
        }
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
        // Task 1375: the shop float is the SUM of the day's drawers — a
        // two-counter shop records two openings and both are in the till.
        if (($cashRecon['opening_float'] ?? null) === null) {
            $recordedOpening = \App\Models\PosDayOpening::totalForDate($companyId, $date, $dcBranchId);
            if ($recordedOpening !== null) {
                $cashRecon = $cashRecon ?? [];
                $cashRecon['opening_float'] = $recordedOpening;
                $cashRecon['counted_cash'] = $cashRecon['counted_cash'] ?? null;
            }
        }
        // Task 516: a stranded PRIOR day may be legitimately empty (its local
        // bills were archived by a newer day's backlog wash) — allow a
        // zero-figure close so it leaves the stranded banner instead of
        // erroring "no transactions" forever. STRICTLY limited to dates the
        // stranded-day detector returns (i.e. days that actually have bills,
        // archived included) — an arbitrary never-traded past date must NOT
        // mint a fabricated zero Z-report. Today's close stays strict too.
        $allowEmpty = $date < \App\Services\PosBusinessDay::current($companyId)
            && $this->unclosedPriorBusinessDays($companyId, null, false, $dcBranchId)->contains($date);

        // THE ONE-CLICK CLEAR runs here — deliberately last. Validation, the
        // cashier/override authority checks and the "already closed" check are
        // all behind us, so this is the last point where the close can still be
        // refused for a reason that has nothing to do with the blockers.
        if ($wantsClear) {
            if (PosDayCloseReport::where('company_id', $companyId)->forBranch($dcBranchId)->whereDate('report_date', $date)->exists()) {
                return back()->with('error', __('pos.dayclose_report_exists'));
            }
            // All-or-nothing: one row that refuses to clear rolls the WHOLE
            // clear back, so a shop can never end up half-cleared with the day
            // still open (some orders cancelled, some bills untouched).
            try {
                $cleared = DB::transaction(function () use ($companyId, $company, $date, $user, $request, $dcBranchId) {
                    $c = $this->clearDayCloseBlockers($companyId, $company, $date, $user, $request, $dcBranchId);
                    if (! $c['ok']) {
                        throw new \RuntimeException($c['message']);
                    }

                    return $c;
                });
            } catch (\RuntimeException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }
            $clearedNote = $cleared['note'];

            // The hard blocks keep the last word — if anything still stands
            // (a race with another counter), the close refuses as always.
            $openAtClose = $this->openHeldOrdersSummary($companyId, $company);
            if ($openAtClose->count > 0) {
                return back()->with('error', __('pos.dayclose_blocked_open_orders', [
                    'count' => $openAtClose->count,
                    'tables' => $openAtClose->tableNumbers !== '' ? ' (' . __('pos.dc_open_tables_list', ['tables' => $openAtClose->tableNumbers]) . ')' : '',
                ]));
            }
            $pendingDel = $this->undispatchedDeliverySummary($companyId, $company, $date);
            if ($pendingDel->count > 0) {
                return back()->with('error', __('pos.dayclose_blocked_undispatched', ['count' => $pendingDel->count]));
            }
        }

        $result = $this->performDayClose($companyId, $date, $user?->id, $request->input('notes'), $cashRecon, $allowEmpty, $actionOverride, $dcBranchId);

        // A close that fails AFTER the clear must still say what was cleared —
        // the shop may never be left guessing which bills we touched.
        if ($result['status'] === 'exists') {
            return back()->with('error', trim(__('pos.dayclose_report_exists') . ' ' . $clearedNote));
        }
        if ($result['status'] === 'empty') {
            return back()->with('error', trim(__('pos.dayclose_no_transactions') . ' ' . $clearedNote));
        }

        $msg = __('pos.dayclose_report_generated', ['number' => $result['report_number'], 'date' => \Carbon\Carbon::parse($date)->format('d M Y')]);
        // Task 1360: name the branch that was closed — with per-branch closes,
        // "which day did I just freeze" now has two halves, date AND branch.
        if ($dcBranchName = $this->dayCloseBranchName($dcBranchId)) {
            $msg .= ' (' . $dcBranchName . ')';
        }
        if ($result['archived'] > 0) {
            $msg .= __('pos.dayclose_bills_archived', ['count' => $result['archived']]);
        }
        if (($result['deleted'] ?? 0) > 0) {
            $msg .= __('pos.dayclose_bills_deleted', ['count' => $result['deleted']]);
        }
        // Task 690 (parity with FBR): rider-khata delete-guard count — bills
        // picked for delete but spared because rider cash is still unsettled
        // (they were archived instead). Sourced from the Z-report local_summary.
        $riderGuardedTotal = array_sum(array_column($result['summary'] ?? [], 'rider_guarded'));
        if ($riderGuardedTotal > 0) {
            $msg .= __('pos.dayclose_bills_rider_guarded', ['count' => $riderGuardedTotal]);
        }
        $backlogSwept = array_sum(array_column($result['summary'] ?? [], 'backlog'));
        if ($backlogSwept > 0) {
            $msg .= __('pos.dayclose_backlog_included', ['count' => $backlogSwept]);
        }
        // What the one-click clear actually did, spelled out in the same flash —
        // the shop must never wonder which orders/bills the close touched.
        if ($clearedNote !== '') {
            $msg .= ' ' . $clearedNote;
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
        // Parked-bill broom (owner, 23 Aug 2026): carts parked on an EARLIER day
        // are abandoned by definition — that day is closed and they were never
        // part of its totals. Today's parks survive untouched.
        $parkedSwept = \App\Services\PosParkedBills::purgeBeforeDay(\App\Services\PosParkedBills::PRA_TABLE, $companyId, $date);
        if ($parkedSwept > 0) {
            $msg .= __('pos.hs_day_close_cleared', ['count' => $parkedSwept]);
        }
        return back()->with('success', $msg);
    }

    /**
     * The day's bill set for ONE close scope — the exact set performDayClose
     * freezes its figures from (PRA-set only, branch-scoped). Task 1375: a
     * per-counter close must reconcile against the same rupees the Z-report
     * will, or a counter's difference would disagree with the shop's.
     */
    private function dayCloseTransactionSet(int $companyId, string $date, ?int $branchId)
    {
        return PosTransaction::where('company_id', $companyId)
            ->where('business_date', $date)
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->where(fn ($q) => $this->scopeToBranch($q, $branchId))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * CLOSE ONE COUNTER (Task 1375).
     *
     * A two/three-counter shop keeps separate cash at every counter, so the
     * evening count happens counter by counter: this freezes ONE drawer's
     * opening / cash sales / expected / counted / difference and touches no
     * bill — the other counters keep billing exactly as before. Only the closed
     * counter stops (see the sale guard in store()).
     *
     * The SHOP's day closes automatically once every drawer that took a bill
     * today has been closed; if a hard blocker (open orders, undispatched
     * deliveries) is in the way, the counter close still stands and the message
     * says why the day did not end.
     */
    public function closeCounter(Request $request)
    {
        // Same authority as the shop day-close (owner rule 5 Aug 2026).
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($user && !\App\Services\PosAccessService::dayCloseAllowed($user)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (!\App\Services\PosCounterDrawer::ready()) {
            return back()->with('error', __('pos.opening_cash_feature_setup'));
        }
        // A close belongs to ONE branch's drawer — same rule as the day close.
        if ($this->dayCloseAllBranchesView()) {
            return back()->with('error', __('pos.dayclose_pick_branch'));
        }
        $branchId = $this->dayCloseBranchId();
        // Counting a drawer is a LIVE action: always the open trading day.
        $date = \App\Services\PosBusinessDay::current($companyId);

        $request->validate([
            'terminal_id' => 'required|integer|min:0',
            'opening_float' => 'nullable|numeric|min:0|max:99999999',
            'counted_cash' => 'nullable|numeric|min:0|max:99999999',
            'notes' => 'nullable|string|max:500',
        ]);

        // Once the Z-report exists the day is frozen — nothing more to close.
        $alreadyClosed = PosDayCloseReport::where('company_id', $companyId)
            ->forBranch($branchId)
            ->where('report_date', $date)
            ->exists();
        if ($alreadyClosed) {
            return back()->with('error', __('pos.dayclose_report_exists'));
        }

        $terminalId = (int) $request->input('terminal_id');
        if ($terminalId > 0 && !\App\Models\PosTerminal::where('company_id', $companyId)->where('id', $terminalId)->exists()) {
            return back()->with('error', __('pos.counter_invalid'));
        }
        if (\App\Services\PosCounterDrawer::isClosed($companyId, $terminalId, $date)) {
            return back()->with('error', __('pos.counter_already_closed'));
        }

        $transactions = $this->dayCloseTransactionSet($companyId, $date, $branchId);
        $riderFigures = $this->buildRiderDayFigures($companyId, $date, null, $branchId);
        $rows = \App\Services\PosCounterDrawer::rows($companyId, $branchId, $date, $transactions, $riderFigures);
        $row = $rows->firstWhere('terminal_id', $terminalId);
        if (!$row) {
            return back()->with('error', __('pos.counter_invalid'));
        }

        // An on-screen correction of the morning float wins; otherwise the
        // drawer's recorded opening. Counted stays NULL when nobody counted —
        // NULL is "not counted", never zero.
        $opening = $request->filled('opening_float')
            ? round((float) $request->input('opening_float'), 2)
            : $row['opening'];
        $counted = $request->filled('counted_cash') ? round((float) $request->input('counted_cash'), 2) : null;
        $expected = round((float) ($opening ?? 0) + (float) $row['cash_sales']
            - (float) $row['rider_out'] + (float) $row['rider_in'], 2);

        \App\Models\PosCounterClose::updateOrCreate(
            [
                'company_id' => $companyId,
                'branch_id' => PosDayCloseReport::branchKey($branchId),
                'terminal_id' => $terminalId,
                'business_date' => $date,
            ],
            [
                'opening_float' => $opening,
                'cash_sales' => $row['cash_sales'],
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'cash_variance' => $counted === null ? null : round($counted - $expected, 2),
                'bills_count' => $row['bills'],
                'total_sales' => $row['total'],
                'closed_by' => $user?->id,
                'notes' => $request->input('notes'),
                'closed_at' => now(),
            ]
        );

        $msg = __('pos.counter_closed_success', ['counter' => $row['name']]);

        // Every drawer counted → the shop's day can end. Rebuilt from scratch so
        // a counter closed a second earlier on another screen counts too.
        $freshRows = \App\Services\PosCounterDrawer::rows($companyId, $branchId, $date, $transactions, $riderFigures);
        if (!\App\Services\PosCounterDrawer::allDrawersClosed($freshRows)) {
            $pending = \App\Services\PosCounterDrawer::pendingDrawers($freshRows);

            return back()->with('success', $msg . ' ' . __('pos.counter_day_not_closed_yet', [
                'count' => $pending->count(),
                'counters' => $pending->pluck('name')->implode(', '),
            ]));
        }

        // The same hard blocks the manual shop close obeys — a counter's cash
        // count can never wave an open table or an undispatched delivery through.
        $openAtClose = $this->openHeldOrdersSummary($companyId, $company);
        if ($openAtClose->count > 0) {
            return back()->with('success', $msg . ' ' . __('pos.dayclose_blocked_open_orders', [
                'count' => $openAtClose->count,
                'tables' => $openAtClose->tableNumbers !== '' ? ' (' . __('pos.dc_open_tables_list', ['tables' => $openAtClose->tableNumbers]) . ')' : '',
            ]));
        }
        $pendingDel = $this->undispatchedDeliverySummary($companyId, $company, $date);
        if ($pendingDel->count > 0) {
            return back()->with('success', $msg . ' ' . __('pos.dayclose_blocked_undispatched', ['count' => $pendingDel->count]));
        }

        // Shop figures come from the counters' own counts, so the Z-report's
        // opening/counted/difference is exactly the sum of the drawers.
        $result = $this->performDayClose(
            $companyId, $date, $user?->id, $request->input('notes'),
            \App\Services\PosCounterDrawer::shopReconFromCloses($freshRows),
            false, null, $branchId
        );
        if (($result['status'] ?? '') !== 'created') {
            return back()->with('success', $msg);
        }

        return back()->with('success', $msg . ' ' . __('pos.counter_all_closed_day_closed', [
            'number' => $result['report_number'],
        ]));
    }

    /**
     * REOPEN A COUNTER (Task 1375) — admin/manager only, and only while the
     * shop's day is still open. Miscounted drawer, or a late bill that must go
     * through that counter: deleting the close row puts the counter back on the
     * floor. Once the Z-report exists the day is frozen and this refuses.
     */
    public function reopenCounter(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($user && !\App\Services\PosAccessService::dayCloseAllowed($user)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        // Undoing a cash count is an authority decision, not a counting one —
        // a cashier with day-close access still may not reopen (same rule as
        // the wash override).
        if ($user && $user->isPosCashier()) {
            return back()->with('error', __('pos.only_admin_change_setting'));
        }
        $companyId = app('currentCompanyId');

        if (!\App\Services\PosCounterDrawer::ready()) {
            return back()->with('error', __('pos.opening_cash_feature_setup'));
        }
        if ($this->dayCloseAllBranchesView()) {
            return back()->with('error', __('pos.dayclose_pick_branch'));
        }
        $branchId = $this->dayCloseBranchId();
        $date = \App\Services\PosBusinessDay::current($companyId);

        $request->validate(['terminal_id' => 'required|integer|min:0']);

        if (PosDayCloseReport::where('company_id', $companyId)->forBranch($branchId)->where('report_date', $date)->exists()) {
            return back()->with('error', __('pos.dayclose_report_exists'));
        }

        $close = \App\Models\PosCounterClose::where('company_id', $companyId)
            ->forBranch($branchId)
            ->where('terminal_id', (int) $request->input('terminal_id'))
            ->whereDate('business_date', $date)
            ->first();
        if (!$close) {
            return back()->with('error', __('pos.counter_not_closed'));
        }

        $name = (int) $close->terminal_id === 0
            ? __('pos.counter_not_set')
            : (\App\Services\PosCounterDrawer::names($companyId)[(int) $close->terminal_id] ?? (__('pos.counter_word') . ' #' . $close->terminal_id));
        $close->delete();

        return back()->with('success', __('pos.counter_reopened', ['counter' => $name]));
    }

    /**
     * Comprehensive day-close analytics (owner request Jul 2026) shared by the
     * day-close page, the A4 PDF and the 80mm thermal Z-report: category-wise
     * sales, top products, hourly breakdown, PRA submission health, discount &
     * deals summary, order-type split (restaurant-gated), averages and
     * yesterday / last-week comparisons. Pure read — computed live from the
     * already-filtered PRA-mode transaction set (local bills stay excluded).
     */
    private function buildDayCloseAnalytics(int $companyId, string $date, $transactions, ?Company $company, ?int $branchId = null): object
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
        // Cheap column check FIRST: restaurantAllowed() hits subscriptions —
        // non-restaurant companies (the vast majority) must short-circuit
        // before any plan lookup (also keeps minimal-schema tests green).
        $restaurantEnabled = $company
            && (bool) ($company->restaurant_mode ?? false)
            && \App\Services\PosFeatureService::restaurantAllowed($company);
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
        // Task 1360: compare this branch's yesterday with this branch's today —
        // a company-wide baseline would show a branch "down 60%" every day.
        $compareFor = function (string $cmpDate) use ($companyId, $branchId) {
            $row = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('business_date', $cmpDate)
                ->where(function ($q) {
                    $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
                })
                ->where(fn ($q) => $this->scopeToBranch($q, $branchId))
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
            // Range analytics are GROSS sales analytics (categories, profit,
            // hourly, cashier ranking) — return/credit-note rows are EXCLUDED
            // here (like topItems), not netted; netted figures live in the
            // reports() headline queries and day-close. Schema-guarded.
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type'), function ($q) {
                $q->where(function ($w) {
                    $w->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
                });
            })
            // Task 1186: derived viewers' own cross-stream rows join the analytics.
            ->tap(fn ($q) => $this->applyReportFilters($q, $tab, $cashierFilter, $user))
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->get(array_merge(
                ['id', 'created_at', 'business_date', 'created_by', 'customer_id', 'customer_name', 'customer_phone', 'subtotal', 'total_amount', 'tax_amount', 'discount_amount', 'payment_method'],
                // Schema-guarded: order_type column added Jul 2026; pre-migration PROD
                // schemas omit it — unconditional selection would throw unknown-column SQL.
                \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'order_type') ? ['order_type'] : [],
                // terminal_id (Task 1349) powers the counter-wise split below. Guarded
                // for the same reason: minimal test schemas omit the column entirely.
                \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'terminal_id') ? ['terminal_id'] : []
            ));

        $ids = $transactions->pluck('id')->all();
        // PROFIT-FREEZE (Task 423, owner decision Aug 2026): cost basis = the cost_price
        // SNAPSHOT frozen on each sold line at sale time — same basis as FBR POS (Task 416).
        // NEVER the product's current cost: a kharid-rate edit must not retro-rewrite a past
        // range's profit. Lines without a stored snapshot are cost-unknown and excluded
        // (coverage_pct shows it). Column may not exist on older PROD schema — hasColumn guard.
        $hasFrozenCost = \Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'cost_price');
        $itemCols = ['transaction_id', 'item_type', 'item_id', 'item_name', 'quantity', 'subtotal', 'tax_amount', 'item_discount_amount'];
        if ($hasFrozenCost) {
            $itemCols[] = 'cost_price';
        }
        $items = empty($ids) ? collect() : \App\Models\PosTransactionItem::whereIn('transaction_id', $ids)
            ->get($itemCols);

        // Category resolution: productMap provides the category label.
        // When hasFrozenCost is TRUE, cost comes from the per-line item snapshot.
        // When FALSE (pre-migration fallback), cost comes from live pos_products.cost_price
        // so the profit widget keeps working exactly as before the migration ran on PROD.
        $productIds = $items->where('item_type', 'product')->pluck('item_id')->filter()->unique()->values();
        $productMapCols = $hasFrozenCost ? ['id', 'category'] : ['id', 'category', 'cost_price'];
        $productMap = $productIds->isEmpty() ? collect() : \App\Models\PosProduct::where('company_id', $companyId)
            ->whereIn('id', $productIds)->get($productMapCols)->keyBy('id');

        $items->each(function ($it) use ($productMap, $isAdminView, $hasFrozenCost) {
            $cost = null;
            if ($it->item_type === 'product') {
                $p = $productMap[$it->item_id] ?? null;
                $cat = trim((string) ($p->category ?? ''));
                $it->resolved_category = $cat !== '' ? $cat : 'Uncategorized';
                if ($isAdminView) {
                    if ($hasFrozenCost) {
                        // Frozen snapshot: only count lines with a non-zero sale-time capture.
                        // Old bills (NULL snapshot) are excluded; coverage_pct shows the gap.
                        if (isset($it->cost_price) && (float) $it->cost_price > 0) {
                            $cost = (float) $it->cost_price * (float) $it->quantity;
                        }
                    } else {
                        // Pre-migration fallback: live product cost (same behaviour as before Task 423).
                        if ($p && $p->cost_price !== null && (float) $p->cost_price > 0) {
                            $cost = (float) $p->cost_price * (float) $it->quantity;
                        }
                    }
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
            // Task 448 (mirrors FBR munafa Task 426): surface WHY lines are missing
            // from profit — product lines without a frozen cost snapshot are excluded,
            // never estimated. unknown_* feed the setup/partial-exclusion banners.
            $unknownItems = $items->filter(fn ($it) => $it->item_type === 'product' && $it->resolved_cost === null);
            $profit = (object) [
                'cost' => $cost,
                'revenue' => $costedRevenue,
                'profit' => round($costedRevenue - $cost, 2),
                'margin_pct' => $costedRevenue > 0 ? round(($costedRevenue - $cost) / $costedRevenue * 100, 1) : null,
                'coverage_pct' => $productQty > 0 ? (int) round($costedQty / $productQty * 100) : 0,
                'unknown_lines' => $unknownItems->count(),
                'unknown_sale_value' => (float) $unknownItems->sum('subtotal'),
                'product_revenue' => (float) $items->where('item_type', 'product')->sum('subtotal'),
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

        // Task 1349: SALES BY COUNTER (terminal) — mirrors the cashier block.
        // Only bills that actually carry a counter are grouped, so a shop that
        // never picked one gets an empty list and the section stays hidden.
        $terminalNames = \Schema::hasTable('pos_terminals')
            ? PosTerminal::where('company_id', $companyId)->pluck('terminal_name', 'id')
            : collect();
        $terminals = $transactions->filter(fn ($t) => !empty($t->terminal_id))
            ->groupBy('terminal_id')
            ->map(function ($g) use ($terminalNames) {
                $tid = $g->first()->terminal_id;
                $revenue = (float) $g->sum('total_amount');
                return (object) [
                    'name' => $terminalNames[$tid] ?? ('#' . $tid),
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

        // Order Type breakdown (Task 982): only meaningful for restaurant-mode
        // companies; non-restaurant companies always have NULL order_type so the
        // breakdown would be a single "General" row — hide it entirely.
        $isRestaurant = (bool) ($company->restaurant_mode ?? false);
        $orderTypes = collect();
        if ($isRestaurant && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'order_type')) {
            $labelMap = [
                'dine_in'  => 'Dine-In',
                'takeaway' => 'Takeaway',
                'delivery' => 'Delivery',
            ];
            $orderTypes = $transactions
                ->groupBy(fn ($t) => in_array($t->order_type, ['dine_in', 'takeaway', 'delivery'], true)
                    ? $t->order_type : 'other')
                ->reject(fn ($g, $key) => $key === 'other')
                ->map(function ($g, $key) use ($labelMap) {
                    return (object) [
                        'label'   => $labelMap[$key] ?? 'Other',
                        'count'   => $g->count(),
                        'revenue' => (float) $g->sum('total_amount'),
                        'tax'     => (float) $g->sum('tax_amount'),
                    ];
                })
                ->sortByDesc('revenue');
        }

        // Previous equal-length period (immediately before the range, same filters).
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $prevFrom = $from->copy()->subDays($days)->startOfDay();
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevRow = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->tap(fn ($q) => $this->applyReportFilters($q, $tab, $cashierFilter, $user))
            ->whereBetween('business_date', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as revenue, COALESCE(SUM(tax_amount),0) as tax')
            ->first();
        $pct = function (float $prev, float $cur): ?float {
            return $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;
        };

        // Monthly/range wastage line (Task 595): owner's "mahine mein kitna maal
        // zaya hua" — spoiled-goods returns (transaction_type='return' AND
        // is_wastage=1) for the SAME range/tab/cashier filters. Separate query
        // because $transactions excludes return rows. Schema-guarded (PROD drift):
        // null = column missing, hide the line entirely.
        $wastage = null;
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_wastage')) {
            $wRows = PosTransaction::where('company_id', $companyId)
                ->where('status', 'completed')
                ->where('transaction_type', 'return')
                ->where('is_wastage', true)
                ->tap(fn ($q) => $this->applyReportFilters($q, $tab, $cashierFilter, $user))
                ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
                ->get(['id', 'total_amount']);
            // Top wasted items (Task 597): item-wise ranking so the owner sees
            // WHICH maal is spoiling — qty + Rs per item_name, worst first.
            // Return-line quantities/subtotals are stored positive on return rows.
            $wItems = collect();
            if ($wRows->isNotEmpty()) {
                $wItems = PosTransactionItem::whereIn('transaction_id', $wRows->pluck('id'))
                    ->get(['item_name', 'quantity', 'subtotal'])
                    ->groupBy(fn ($it) => trim((string) $it->item_name) !== '' ? $it->item_name : '—')
                    ->map(fn ($g, $name) => (object) [
                        'name' => $name,
                        'qty' => abs((float) $g->sum('quantity')),
                        'amount' => round(abs((float) $g->sum('subtotal')), 2),
                    ])
                    ->sortByDesc('amount')->take(15)->values();
            }
            $wastage = (object) [
                'count' => $wRows->count(),
                'amount' => round(abs((float) $wRows->sum('total_amount')), 2),
                'items' => $wItems,
            ];
        }

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
            'wastage' => $wastage,
            'previous' => $previous,
            'categories' => $categories,
            'profit' => $profit,
            'is_admin_view' => $isAdminView,
            'daily' => $daily,
            'hourly' => $hourly,
            'cashiers' => $cashiers,
            'terminals' => $terminals,
            'waiters' => $waiters,
            'top_customers' => $topCustomers,
            'payments' => $payments,
            'is_restaurant' => $isRestaurant,
            'order_types' => $orderTypes,
        ];
    }

    /**
     * Task 1349: COUNTER-WISE (terminal) breakdown for the day-close surfaces —
     * page, PDF, thermal and the X-report. Mirrors $cashierBreakdown exactly:
     * figures are SIGNED (refunds net revenue/tax) while counts stay sales-only.
     *
     * Names come from ONE pluck instead of $t->terminal, so the four callers'
     * with() lists stay untouched (prod runs strict lazy-loading and would throw
     * on an un-eager-loaded relation). Returns an EMPTY collection when NOT ONE
     * bill carries a counter — every view hides the section for shops that
     * never picked counters, so nothing changes for them.
     *
     * @param  \Illuminate\Support\Collection  $transactions
     * @return \Illuminate\Support\Collection
     */
    private function buildTerminalBreakdown($transactions, int $companyId)
    {
        if ($transactions->filter(fn ($t) => !empty($t->terminal_id))->isEmpty()) {
            return collect();
        }

        $names = \Schema::hasTable('pos_terminals')
            ? PosTerminal::where('company_id', $companyId)->pluck('terminal_name', 'id')
            : collect();

        return $transactions
            ->groupBy(fn ($t) => $t->terminal_id
                ? ($names[$t->terminal_id] ?? (__('pos.counter_word') . ' #' . $t->terminal_id))
                : __('pos.counter_not_set'))
            ->map(function ($group) {
                $sign = fn ($t) => ($t->transaction_type ?? 'sale') === 'return' ? -1 : 1;

                return (object) [
                    'count' => $group->filter(fn ($t) => ($t->transaction_type ?? 'sale') !== 'return')->count(),
                    'revenue' => $group->sum(fn ($t) => $sign($t) * (float) $t->total_amount),
                    'tax' => $group->sum(fn ($t) => $sign($t) * (float) $t->tax_amount),
                ];
            })
            ->sortByDesc('revenue');
    }

    /**
     * Shared range parsing for the reports analytics surfaces: a fresh visit
     * defaults to today, swaps reversed inputs, caps the window at 366 days.
     *
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    private function resolveReportRange(Request $request): array
    {
        try {
            $from = $request->filled('from') ? \Carbon\Carbon::parse($request->input('from'))->startOfDay() : now()->startOfDay();
        } catch (\Throwable) {
            $from = now()->startOfDay();
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
    private function buildRiderDayFigures(int $companyId, string $date, ?int $onlyCreatedBy = null, ?int $branchId = null): array
    {
        $empty = ['active' => false, 'riders' => [], 'cash_out' => 0.0, 'cash_in' => 0.0];
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_riders') || !\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_id')) {
                return $empty;
            }

            // Task 1197: an isolated cashier's day-close/X-report PREVIEW scopes
            // rider recon to bills THEY created (settling stays shared work —
            // this narrows the preview figures only, never the stored Z).
            $dayBills = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('business_date', $date)
                ->whereNotNull('rider_id')
                ->when($onlyCreatedBy, fn ($q) => $q->where('created_by', $onlyCreatedBy))
                // Task 1360: rider cash reconciles against ONE branch's drawer.
                ->where(fn ($q) => $this->scopeToBranch($q, $branchId))
                ->get();

            // rider_settled_at stays on the REAL calendar date (settlement
            // timestamps carry no business date) — known v1 limitation: a 1 AM
            // settlement counts toward the calendar day, not the open trading day.
            // Partial settlements (Task 525): new settlement rows carry an
            // 'allocation' breakdown — for those, cash-in comes from the
            // allocation entries (exact rupees received today against older
            // bills, partial or full). The legacy bill-based query stays for
            // pre-feature settlements (no allocation) so the transition day
            // never double-counts.
            $hasAllocation = \Illuminate\Support\Facades\Schema::hasColumn('pos_rider_settlements', 'allocation');
            $legacyCashInQ = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->whereNotNull('rider_id')
                ->where('payment_method', PosPaymentBuckets::CASH)
                ->whereNotNull('rider_settlement_id')
                ->whereDate('rider_settled_at', $date)
                ->where('business_date', '<', $date)
                ->where(function ($q) {
                    $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
                })
                ->when($onlyCreatedBy, fn ($q) => $q->where('created_by', $onlyCreatedBy))
                ->where(fn ($q) => $this->scopeToBranch($q, $branchId));
            if ($hasAllocation) {
                $legacyCashInQ->whereNotIn('rider_settlement_id', function ($q) use ($companyId) {
                    $q->select('id')->from('pos_rider_settlements')
                        ->where('company_id', $companyId)
                        ->whereNotNull('allocation');
                });
            }
            $cashIn = (float) $legacyCashInQ->sum('total_amount');
            if ($hasAllocation) {
                $allocCashIn = 0.0;
                $allocSettlements = \App\Models\PosRiderSettlement::where('company_id', $companyId)
                    ->where('panel', 'pra')
                    ->whereNotNull('allocation')
                    ->whereDate('created_at', $date)
                    ->get();
                // Task 1197: allocation entries carry bill_id — the isolated
                // preview counts only rupees applied to the viewer's OWN bills.
                $ownBillIds = null;
                if ($onlyCreatedBy && $allocSettlements->isNotEmpty()) {
                    $allocIds = $allocSettlements->flatMap(fn ($s) => collect((array) $s->allocation)->pluck('bill_id'))->filter()->unique()->values();
                    $ownBillIds = $allocIds->isEmpty() ? collect() : PosTransaction::withoutGlobalScope('hide_archived')
                        ->where('company_id', $companyId)
                        ->whereIn('id', $allocIds)
                        ->where('created_by', $onlyCreatedBy)
                        ->pluck('id')
                        ->flip();
                }
                $allocSettlements->each(function ($s) use (&$allocCashIn, $date, $ownBillIds) {
                    foreach ((array) $s->allocation as $entry) {
                        if ($ownBillIds !== null && !isset($ownBillIds[(int) ($entry['bill_id'] ?? 0)])) {
                            continue;
                        }
                        if (!empty($entry['business_date']) && $entry['business_date'] < $date) {
                            $allocCashIn += (float) ($entry['amount'] ?? 0);
                        }
                    }
                });
                $cashIn += $allocCashIn;
            }

            if ($dayBills->isEmpty() && $cashIn == 0.0) {
                return $empty;
            }

            $isOpenCash = fn ($t) => $t->payment_method === PosPaymentBuckets::CASH
                && !$t->rider_settlement_id
                && $t->delivery_status !== 'returned';

            // Khata remaining per bill — partial cash already received today is
            // IN the drawer, only the unpaid remainder is out with the rider.
            $hasPartialCol = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_partial_paid');
            $remainingOf = fn ($t) => (float) $t->total_amount - ($hasPartialCol ? (float) ($t->rider_partial_paid ?? 0) : 0);

            $cashOut = (float) $dayBills
                ->filter(fn ($t) => ($t->invoice_mode === 'pra' || $t->invoice_mode === null) && $isOpenCash($t))
                ->sum($remainingOf);

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
                    'cash_pending' => round((float) $rows->filter($isOpenCash)->sum($remainingOf), 2),
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
    private function finalizeProvisionalsAtDayClose(int $companyId, Company $company, string $date, ?array $onlyIds = null, array $excludeIds = [], ?int $branchId = null): array
    {
        $sweep = ['finalized' => 0, 'finalized_amount' => 0.0, 'submitted' => 0, 'queued' => 0, 'offline' => 0, 'quota_blocked' => 0, 'skipped' => 0];

        $reportingOn = $company->praReportingActive();
        $agentMode = $company->agentHandlesPra();
        $praService = null;

        // Same selector as the provisional wash set, restricted to COMPLETED bills —
        // a draft is a live/abandoned cart, never something to send to the tax record.
        // Task 677 (bill-by-bill): $onlyIds limits the sweep to explicitly-picked
        // bills; $excludeIds keeps bills the admin marked save/delete OUT of a
        // whole-set finalize (they must wash, not promote).
        $rows = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('business_date', '<=', $date)
            ->whereNull('pra_invoice_number')
            ->where('is_archived', false)
            ->where('invoice_mode', 'local')
            ->where('pra_status', 'local')
            ->where('status', 'completed')
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('id', $onlyIds))
            ->when(!empty($excludeIds), fn ($q) => $q->whereNotIn('id', $excludeIds))
            // Task 1360: a branch's close promotes only its OWN provisionals.
            ->where(fn ($q) => $this->scopeToBranch($q, $branchId))
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

    /**
     * Task 516: bulk-close ALL stranded prior business days in one click.
     * Ends the "close one, another appears" whack-a-mole: shops that don't
     * close daily pile up 20+ open days and had to close each one by one.
     * Iterates chronologically and calls the SAME performDayClose routine per
     * day (opening-float self-heal, local-bill wash policy, archive semantics
     * all identical to a single close) — no parallel close path.
     */
    public function closeAllPriorDays(Request $request)
    {
        // Same authority gate as the single close (owner rule 5 Aug 2026).
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($user && !\App\Services\PosAccessService::dayCloseAllowed($user)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // Task 1360: bulk close follows the SAME scope rule as the single one —
        // a Z-report must belong to the branch it was previewed for, so the
        // company-wide "All branches" view cannot run it either.
        if ($this->dayCloseAllBranchesView()) {
            return back()->with('error', __('pos.dayclose_pick_branch'));
        }
        $dcBranchId = $this->dayCloseBranchId();

        // Same hard block as the single close: open restaurant orders freeze
        // ALL manual closes (un-finalized orders can never be finalized after
        // a close whose backlog wash may sweep their day).
        $openAtClose = $this->openHeldOrdersSummary($companyId, $company);
        if ($openAtClose->count > 0) {
            return back()->with('error', __('pos.dayclose_blocked_open_orders', [
                'count' => $openAtClose->count,
                'tables' => $openAtClose->tableNumbers !== '' ? ' (' . __('pos.dc_open_tables_list', ['tables' => $openAtClose->tableNumbers]) . ')' : '',
            ]));
        }

        if ($this->unclosedPriorBusinessDays($companyId, null, false, $dcBranchId)->isEmpty()) {
            return back()->with('success', __('pos.dc_bulk_none_pending'));
        }

        $closed = 0;
        $zeroDays = 0;
        $archived = 0;
        $deleted = 0;
        // Task 694: rider-khata delete-guard count accumulated across ALL
        // closed days — same 'spared because rider cash unsettled' line the
        // single close shows (Task 690), so the bulk flash explains why some
        // local bills were archived instead of deleted.
        $riderGuarded = 0;
        // Task 684 (ZFC waqia follow-up): undispatched delivery bills now block
        // PER-DAY, not the whole bulk run — days BEFORE the blocker still close
        // (the summary is cumulative ≤date, so a blocker on day D skips D and
        // every later day, never earlier ones). Skipped dates are remembered so
        // re-passes don't double-count and the flash can say WHY they remain.
        $skippedDel = [];
        // The detector returns at most 30 dates per query — RE-QUERY until the
        // backlog is exhausted so 31+ open days still finish in ONE click
        // ("all" must mean all). oldestFirst: pages must come CHRONOLOGICALLY
        // (oldest day first) so each day's backlog wash only ever sweeps its
        // own bills — a newer close would steal older days' local bills.
        // Guard caps the loop; each pass must make progress or we bail.
        for ($pass = 0; $pass < 30; $pass++) {
            $pending = $this->unclosedPriorBusinessDays($companyId, null, true, $dcBranchId); // oldest 30, ascending
            if ($pending->isEmpty()) {
                break;
            }
            $closedThisPass = 0;
            foreach ($pending as $day) {
                // Task 684: undispatched delivery bills freeze THIS day (and,
                // cumulatively, every later one) — skip it, keep closing the
                // rest, and log the reason (same authority as the single close).
                if (isset($skippedDel[$day])) {
                    continue;
                }
                $pendingDel = $this->undispatchedDeliverySummary($companyId, $company, $day);
                if ($pendingDel->count > 0) {
                    $skippedDel[$day] = (int) $pendingDel->count;
                    \Illuminate\Support\Facades\Log::warning('pos bulk day-close skipped day — undispatched deliveries', [
                        'company_id' => $companyId,
                        'date' => $day,
                        'undispatched' => (int) $pendingDel->count,
                    ]);
                    continue;
                }
                // allowEmpty: stranded days with only already-archived bills get
                // a zero-figure Z-report so they finally leave the banner. Safe:
                // every $day comes from the detector (has real bills).
                $result = $this->performDayClose($companyId, $day, $user?->id, null, null, true, null, $dcBranchId);
                if ($result['status'] === 'created') {
                    $closed++;
                    $closedThisPass++;
                    $archived += $result['archived'];
                    $deleted += $result['deleted'] ?? 0;
                    // Task 694: per-day rider-guarded count rides in the same
                    // Z-report local_summary the single close reads (Task 690).
                    $riderGuarded += array_sum(array_column($result['summary'] ?? [], 'rider_guarded'));
                    if ((int) ($result['report']->total_invoices ?? 0) === 0) {
                        $zeroDays++;
                    }
                }
                // 'exists' = already closed (race with another cashier) — skip silently.
            }
            if ($closedThisPass === 0) {
                break; // no progress — never spin
            }
        }

        $msg = __('pos.dc_bulk_done', ['closed' => $closed, 'zero' => $zeroDays]);
        if ($archived > 0) {
            $msg .= __('pos.dayclose_bills_archived', ['count' => $archived]);
        }
        if ($deleted > 0) {
            $msg .= __('pos.dayclose_bills_deleted', ['count' => $deleted]);
        }
        // Task 694: rider-khata delete-guard — bills picked for delete but
        // spared (archived instead) because rider cash is still unsettled.
        if ($riderGuarded > 0) {
            $msg .= __('pos.dayclose_bills_rider_guarded', ['count' => $riderGuarded]);
        }
        // Task 684: say WHY skipped days remain — undispatched delivery bills.
        if (!empty($skippedDel)) {
            $msg .= ' ' . __('pos.dc_bulk_skipped_undispatched', [
                'days' => count($skippedDel),
                'count' => array_sum($skippedDel),
            ]);
        }

        // Honest "all": if anything is somehow still pending after the capped
        // passes (900 days) or a stalled pass, say so instead of implying done.
        $remaining = $this->unclosedPriorBusinessDays($companyId, null, true, $dcBranchId)->count();
        if ($remaining > 0) {
            return redirect()->route('pos.day-close')
                ->with('error', $msg . ' ' . __('pos.dc_bulk_partial', ['remaining' => $remaining]));
        }

        return redirect()->route('pos.day-close')->with('success', $msg);
    }

    /**
     * Returns audit detail for one business day (Task 682): every return
     * (both streams) with parent invoice + who processed it. Used to SNAPSHOT
     * the list on the stored Z-report at close time — after the wash, local
     * return rows may be archived/deleted and a live query would lose them.
     */
    private function buildDayCloseReturnsDetail(int $companyId, string $date, ?int $branchId = null): array
    {
        $rows = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('business_date', $date)
            ->where('transaction_type', 'return')
            // Task 1360: the snapshot belongs to ONE branch's Z-report.
            ->where(fn ($q) => $this->scopeToBranch($q, $branchId))
            ->with('creator')
            ->orderBy('created_at')
            ->get();
        $parents = $rows->isNotEmpty()
            ? PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->whereIn('id', $rows->pluck('parent_transaction_id')->filter()->unique())
                ->pluck('invoice_number', 'id')
            : collect();

        return $rows->map(fn ($t) => [
            'id' => $t->id,
            'invoice_number' => $t->invoice_number,
            'parent_transaction_id' => $t->parent_transaction_id,
            'parent_invoice' => $t->parent_transaction_id ? $parents->get($t->parent_transaction_id) : null,
            'created_at' => $t->created_at?->toIso8601String(),
            'amount' => round((float) $t->total_amount, 2),
            'is_wastage' => (bool) ($t->is_wastage ?? false),
            'processed_by' => $t->creator->name ?? null,
            // Billing-scope stream (mirrors allowedForBillingScope): exempt is
            // visible to every scope; local/pra only to their own scope viewers.
            'stream' => $t->isExemptStream() ? 'exempt' : ($t->isLocalBill() ? 'local' : 'pra'),
        ])->values()->all();
    }

    /**
     * Freeze one business day into a Z-report and run the local-bill wash.
     *
     * Task 1360 — $branchId (LAST arg, push-scope convention) is the close
     * SCOPE: null = the whole company (a branch-less shop, and every close from
     * before branches existed), otherwise this branch's bills plus the legacy
     * un-stamped ones. It must be the same scope the day-close page previewed;
     * every query below funnels through scopeToBranch() so the frozen figures
     * and the wash can never widen past what the cashier saw on screen.
     */
    public function performDayClose(int $companyId, string $date, ?int $closedBy, ?string $notes = null, ?array $cashRecon = null, bool $allowEmpty = false, ?array $actionOverride = null, ?int $branchId = null): array
    {
        $existing = PosDayCloseReport::where('company_id', $companyId)
            ->forBranch($branchId)
            ->whereDate('report_date', $date)
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

        // Per-close override (Task 661): applies to THIS close only — the
        // standing policy on the company row stays untouched, and the auto-close
        // command never passes one (it always runs the standing policy).
        // Resolved BEFORE the finalize sweep so an override to 'finalize' runs it.
        // All downstream guards (rider delete-guard, deleted-count quota
        // counters, draft/backlog rules, return exclusion) apply unchanged.
        if (is_array($actionOverride)) {
            if (in_array($actionOverride['provisional'] ?? null, ['save', 'delete', 'carry', 'finalize'], true)) {
                $provAction = $actionOverride['provisional'];
            }
            if (in_array($actionOverride['final_local'] ?? null, ['save', 'delete'], true)) {
                $finalAction = $actionOverride['final_local'];
            }
        }
        // Bill-by-bill deviations (Task 677): id => finalize|save|delete for
        // THIS close only. Sanitized here too (performDayClose is also called
        // by closeAllPriorDays / the auto-close command, which never pass one).
        $billActions = [];
        foreach ((array) ($actionOverride['bills'] ?? []) as $bid => $act) {
            if (in_array($act, ['finalize', 'save', 'delete'], true) && (int) $bid > 0) {
                $billActions[(int) $bid] = $act;
            }
        }

        // ── AUTO-FINALIZE SWEEP (owner option, Aug 2026): promote every pending
        // provisional through the SAME core path F10 Make Final uses (quota gate,
        // month gate, re-tax + whole-rupee rounding, PRA submit with offline
        // fallback). NO receipt print (customer not present). Leftovers that could
        // not be finalized (quota out, older month, drafts, PRA-failed) are
        // CARRIED — never archived/deleted, they stay finalizable tomorrow.
        $finalizeSweep = null;
        if ($provAction === 'finalize') {
            // Whole-set finalize — but bills the admin explicitly marked
            // save/delete (Task 677) must NOT be promoted first.
            $skipIds = array_keys(array_filter($billActions, fn ($a) => $a !== 'finalize'));
            $finalizeSweep = $this->finalizeProvisionalsAtDayClose($companyId, $company, $date, null, $skipIds, $branchId);
        } elseif (in_array('finalize', $billActions, true)) {
            // Per-bill finalize (Task 677): promote ONLY the named bills; the
            // rest of the provisional set follows the standing/all-box action.
            $onlyIds = array_keys(array_filter($billActions, fn ($a) => $a === 'finalize'));
            $finalizeSweep = $this->finalizeProvisionalsAtDayClose($companyId, $company, $date, $onlyIds, [], $branchId);
        }

        // Local (non-PRA) bills stay OUT of the stored day-close figures — they are
        // visible only in the isolated Local Bills Portal. The purge/archive query
        // below still targets them so day-close archiving keeps working.
        $transactions = PosTransaction::where('company_id', $companyId)
            ->where('business_date', $date)
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            // Task 1360: the frozen figures = exactly the preview's set.
            ->where(fn ($q) => $this->scopeToBranch($q, $branchId))
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
            // Task 1360: another branch's pending locals must not make THIS
            // branch's empty day look closeable (nor get washed by it).
            ->where(fn ($q) => $this->scopeToBranch($q, $branchId))
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

        // Empty-day guard: TODAY's close with zero bills stays refused. But a
        // STRANDED prior day can legitimately be empty — e.g. its local bills
        // were already archived by a NEWER day's backlog wash (they still make
        // the day show as "open" in the stranded banner, which counts archived
        // rows, yet nothing is left to wash). Task 516: $allowEmpty lets those
        // days get a zero-figure Z-report so they finally leave the banner —
        // the "close one, another appears" whack-a-mole ender.
        if ($transactions->isEmpty() && !$hasLocalBills && !$allowEmpty) {
            return ['status' => 'empty', 'report' => null, 'archived' => 0, 'deleted' => 0, 'report_number' => null];
        }

        // Report number: one sequence PER CLOSE SCOPE (Task 1360). A shared
        // company sequence would make Gulberg's Z-numbers jump every time Main
        // Shop closed a day; the branch id in the number keeps two shops'
        // printed reports apart on the owner's desk. Branch-less shops keep the
        // original ZRPT-POS-NNNNN format untouched.
        $reportCount = PosDayCloseReport::where('company_id', $companyId)
            ->forBranch($branchId)
            ->count();
        $reportNumber = 'ZRPT-POS-' . ($branchId ? 'B' . $branchId . '-' : '')
            . str_pad($reportCount + 1, 5, '0', STR_PAD_LEFT);

        // Return / credit-note netting (Task 570): returns live in the PRA set
        // ($transactions) but must NET the Z-report figures, not inflate them.
        // Counts + fiscal serial range stay SALES-only (returns carry RET-
        // numbers outside the USIN sequence).
        $typeReady = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type');
        $returnRows = $typeReady
            ? $transactions->filter(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')->values()
            : collect();
        $saleRows = $typeReady
            ? $transactions->reject(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')->values()
            : $transactions;

        // Shared figure builder (Task 660): the SAME code path the X-Report
        // uses, so Z-Report and X-Report numbers can never diverge.
        $data = array_merge($this->buildDayCloseFigureData($saleRows, $returnRows), [
            'company_id' => $companyId,
            'report_date' => $date,
            'report_number' => $reportNumber,
            'closed_by' => $closedBy,
            'notes' => $notes,
        ]);
        // Task 1360: stamp the scope on the report itself — schema-guarded so a
        // box mid-deploy writes the old shape instead of dropping the whole
        // close (an unfillable/unknown column would silently lose the value).
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_close_reports', 'branch_id')) {
            $data['branch_id'] = PosDayCloseReport::branchKey($branchId);
        }

        // Per-stream split (Task 660, ZFC): PRA vs Local vs Exempt figures with
        // payment buckets + exempt item detail — STORED on the report because
        // the wash below may permanently DELETE reporting-OFF finals (a later
        // recompute from surviving rows would undercount the Local stream —
        // this is also why the old Invoice Summary "Amount" column printed
        // "-"). Schema-guarded (prod drift self-heal).
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_close_reports', 'stream_summary')) {
            // Task 705: the STORED split carries the COMPLETE local stream —
            // L-series provisionals merged in (the PRA-mode figure set above
            // deliberately excludes invoice_mode='local' rows, so the Local
            // box was missing them). Split ONLY: every stored report figure
            // stays computed from the PRA set, and PRA-reporting logic is
            // untouched (compliance boundary).
             $streamSummary = $this->buildDayCloseStreamSplit(
                $this->withLocalStreamRows($transactions, $companyId, $date, null, $branchId)
            );
             // The compact payment split follows the report's PRA figure set,
             // not the extra local rows included in stream_summary.
             $streamSummary['summary_payments'] = $this->buildDayCloseSummaryPaymentSplit($saleRows, $returnRows);
             $data['stream_summary'] = $streamSummary;
        }

        // Returns audit snapshot (Task 682): freeze the per-return detail
        // (invoice, parent, amount, processed-by) on the Z-report — the wash
        // below may archive or permanently DELETE local return rows, so the
        // live query behind the page's audit list can lose them afterwards.
        // Company-wide (BOTH streams) like the live Task-678 list; the page
        // filters by the viewer's billing scope via the stored 'stream' key.
        if ($typeReady && \Illuminate\Support\Facades\Schema::hasColumn('pos_day_close_reports', 'returns_detail')) {
            $data['returns_detail'] = $this->buildDayCloseReturnsDetail($companyId, $date, $branchId);
        }

        // Delivery Riders (Jul 2026): rider cash figures for this day — computed
        // BEFORE the wash so archived/deleted local bills still count.
        $riderFigures = $this->buildRiderDayFigures($companyId, $date, null, $branchId);

        // Cash reconciliation (Z-report): expected = opening float + cash sales
        // − rider cash still out with riders (unsettled today's cash deliveries)
        // + rider cash received today for earlier days' bills;
        // variance = counted − expected. Columns are nullable + schema-guarded
        // (prod drift self-heal).
        // Opening Cash Balance (Jul 2026): the day-start recorded opening is the
        // fallback when the close request didn't carry one — this also covers the
        // MIDNIGHT AUTO close ($cashRecon null), so the Z-report still shows the
        // opening + expected cash even without an evening count.
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_close_reports', 'opening_float')) {
            $openingFloat = $cashRecon['opening_float'] ?? null;
            $countedCash = $cashRecon['counted_cash'] ?? null;
            // Task 1375: SUM of the day's drawers — a counter shop records one
            // float per counter and every one of them sits in the shop's till.
            if ($openingFloat === null) {
                $openingFloat = \App\Models\PosDayOpening::totalForDate($companyId, $date, $branchId);
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

        // Counter-wise cash reconciliation (Task 1375): frozen on the Z-report
        // BEFORE the hash, same reason stream_summary is — the wash below can
        // archive/delete rows, so a later recompute would undercount a counter's
        // drawer. Counter-less shops write nothing and their report is byte-for-
        // byte what it always was. Schema-guarded (prod drift self-heal).
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_close_reports', 'counter_summary')) {
            try {
                $counterRows = \App\Services\PosCounterDrawer::rows($companyId, $branchId, $date, $transactions, $riderFigures);
                if ($counterRows->isNotEmpty()) {
                    $data['counter_summary'] = $counterRows->all();
                }
            } catch (\Throwable $e) {
                // Counter recon is reporting sugar — never block a day close.
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
        $riderGuardReady = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_id');

        $archivedCount = 0;
        $deletedCount = 0;
        $report = null;
        $localSummary = [];
        \DB::transaction(function () use ($data, $companyId, $date, $branchId, $provAction, $finalAction, $spendPersist, $riderGuardReady, $riderFigures, $finalizeSweep, $billActions, &$archivedCount, &$deletedCount, &$report, &$localSummary) {
            // Preserve every existing L-reference before the wash can archive or
            // permanently delete its row. This is a no-op on old schemas during
            // the migration window; the migration backfills the same high-water.
            \App\Services\PosLocalSeries::preserveHighWaterMark($companyId);

            $report = PosDayCloseReport::create($data);

            // BACKLOG SWEEP (owner rule Jul 2026): wash covers bills up to AND
            // INCLUDING the close date, so local bills left over from earlier
            // un-closed dates finally get washed instead of lingering forever.
            // Task 1360: ...but only within the branch being closed. Before
            // this, Gulberg's close archived (or permanently DELETED, under the
            // delete policy) Main Shop's untouched local bills.
            $baseQuery = function () use ($companyId, $date, $branchId) {
                return PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->where('business_date', '<=', $date)
                    ->whereNull('pra_invoice_number')
                    ->where('is_archived', false)
                    ->where(fn ($q) => $this->scopeToBranch($q, $branchId));
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
                        ->whereNull('pra_status')
                        // Returns/credit notes (Task 570) are NEVER washed:
                        // deleting one would desync the parent's returned_quantity,
                        // un-net the reports, and (via deleted_final_count) eat
                        // quota the return never consumed. Schema-guarded.
                        ->when(\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type'), function ($q) {
                            $q->where(function ($w) {
                                $w->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
                            });
                        }),
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
                // merge its numbers into the Z-report detail (whole-set 'finalize'
                // policy OR per-bill finalize picks, Task 677). Remaining rows are
                // the leftovers (quota out / older month / drafts / races) — they
                // resolve to 'carry' below: never archived or deleted.
                if ($billKind === 'provisional' && $finalizeSweep !== null) {
                    $localSummary[$billKind] = array_merge($localSummary[$billKind], $finalizeSweep);
                }
                if ($rows->isEmpty()) {
                    continue;
                }
                // Bill-by-bill resolution (Task 677): each row follows its own
                // picked action, falling back to the kind's set action. Kind
                // constraints: reporting-OFF finals can only save/delete (they
                // are already final — 'finalize' is meaningless and resolves to
                // the set action). 'finalize' leftovers still present here (the
                // sweep could not promote them) resolve to 'carry'.
                $effective = function ($t) use ($billActions, $set, $billKind) {
                    $act = $billActions[(int) $t->id] ?? $set['action'];
                    if ($billKind === 'final_local' && !in_array($act, ['save', 'delete'], true)) {
                        $act = in_array($set['action'], ['save', 'delete'], true) ? $set['action'] : 'save';
                    }
                    return $act === 'finalize' ? 'carry' : $act;
                };
                $deleteRows = $rows->filter(fn ($t) => $effective($t) === 'delete')->values();
                $saveRows = $rows->filter(fn ($t) => $effective($t) === 'save')->values();
                // CARRY FORWARD rows survive the wash exactly as they are —
                // still un-archived, still in F10, finalizable tomorrow.
                if (!empty($billActions)) {
                    // Z-report detail: how the per-bill picks split this kind.
                    $localSummary[$billKind]['per_bill'] = [
                        'save' => $saveRows->count(),
                        'delete' => $deleteRows->count(),
                        'carry' => $rows->count() - $saveRows->count() - $deleteRows->count(),
                    ];
                }
                // Rider DELETE-guard: unsettled cash delivery bills are ARCHIVED
                // instead of deleted (khata proof survives) — applies to per-bill
                // picks exactly like the whole-set policy. Settled / returned /
                // non-cash rider bills wash normally.
                if ($deleteRows->isNotEmpty() && $riderGuardReady) {
                    $riderGuarded = $deleteRows->filter(fn ($t) => $t->rider_id
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
                        $guardedIds = $riderGuarded->pluck('id')->all();
                        $deleteRows = $deleteRows->reject(fn ($t) => in_array($t->id, $guardedIds, true))->values();
                    }
                }
                if ($deleteRows->isNotEmpty()) {
                    if ($spendPersist) {
                        $now = now();
                        $snapshots = $deleteRows
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
                    $ids = $deleteRows->pluck('id')->all();
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
                    $deletedByKind[$billKind] += $deleteRows
                        ->filter(fn ($t) => ($d = $t->business_date ?: $t->created_at?->toDateString())
                            && $d >= $monthStart->toDateString() && $d <= $monthEnd->toDateString())
                        ->count();
                }
                if ($saveRows->isNotEmpty()) {
                    $archivedCount += PosTransaction::withoutGlobalScope('hide_archived')
                        ->whereIn('id', $saveRows->pluck('id')->all())
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
            // report so the Z-report view shows a durable record if any orders
            // were open at the moment performDayClose ran. In normal operation
            // this will be empty: manual close is hard-blocked while orders are
            // open (closeDayReport guard), and the 6 AM auto-close now SKIPS
            // (skip_alert policy, owner 10 Aug 2026). Kept as a defensive
            // audit trail for edge cases. try/catch: must never fail a close.
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

        // Owner/manager phone push with the day's totals (Task #1142) — queued
        // only AFTER the report transaction committed; fire-and-forget (can
        // never fail the close). Bulk/auto closes notify per closed day too.
        try {
            \App\Services\PosPushService::queueDayClosePush($companyId, [
                'date' => $date,
                'total' => (float) ($data['total_amount'] ?? 0),
                'cash' => (float) ($data['cash_amount'] ?? 0),
                'invoices' => (int) ($data['total_invoices'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            // push is additive — the close already succeeded
        }

        return ['status' => 'created', 'report' => $report, 'archived' => $archivedCount, 'deleted' => $deletedCount, 'report_number' => $reportNumber, 'summary' => $localSummary, 'finalize_sweep' => $finalizeSweep];
    }

     public function dayCloseReportPdf($id, ?Request $request = null)
    {
        // Owner rule (5 Aug 2026): cashier day-close only via company switch / Custom Access tick.
        $dayCloseUser = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($dayCloseUser && !\App\Services\PosAccessService::dayCloseAllowed($dayCloseUser)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        // Task 1197: the stored Z-report is COMPANY-WIDE — an isolated cashier's
        // day-close access is preview-only, never the frozen shop document.
        if ($dayCloseUser && $dayCloseUser->posSalesIsolated()) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
         $request = $request ?: Request::create('/pos/day-close/' . $id . '/pdf', 'GET');
         $isSummaryReport = $this->dayCloseReportMode($request) === 'summary';
        $report = PosDayCloseReport::where('company_id', $companyId)->findOrFail($id);
        // Task 1360: a branch's Z-report stays that branch's document.
        if (!$this->canSeeBranchReport($report)) {
            return redirect()->route('pos.day-close')->with('error', __('pos.dayclose_other_branch_report'));
        }
        $rptBranchId = $this->reportBranchId($report);

        // Day-Close PDF shows HISTORICAL truth — include archived bills so the
        // closed-day report stays consistent even after rows were archived.
        // Local (non-PRA) bills excluded — visible only in the Local Bills Portal.
        $transactions = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('business_date', $report->report_date->toDateString())
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            // Task 1360: rebuild the SAME set the frozen figures came from —
            // the report's own branch stamp, not whoever is printing it.
            ->where(fn ($q) => $this->scopeToBranch($q, $rptBranchId))
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        // Cashier figures are SIGNED (Task 570): refunds net revenue/tax;
        // counts stay sales-only.
        $cashierBreakdown = $transactions->groupBy(fn($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            $sign = fn ($t) => ($t->transaction_type ?? 'sale') === 'return' ? -1 : 1;
            return (object) [
                'count' => $group->filter(fn ($t) => ($t->transaction_type ?? 'sale') !== 'return')->count(),
                'revenue' => $group->sum(fn ($t) => $sign($t) * (float) $t->total_amount),
                'tax' => $group->sum(fn ($t) => $sign($t) * (float) $t->tax_amount),
            ];
        });

        // Task 1349: counter-wise (terminal) breakdown — same signed convention.
        $terminalBreakdown = $this->buildTerminalBreakdown($transactions, $companyId);

        $analytics = $this->buildDayCloseAnalytics($companyId, $report->report_date->toDateString(), $transactions, $company, $rptBranchId);
        // Plan gate: hazri section is Pro+ (views already @if(!empty($hazri))).
        $hazri = PosFeatureService::planAllows($company, 'hazri_enabled')
            ? $this->buildHazriRows($companyId, $report->report_date->toDateString())
            : [];
        // Biometric punches — same plan gate as session hazri.
        $bioPunches = PosFeatureService::planAllows($company, 'hazri_enabled')
            ? $this->buildBiometricRows($companyId, $report->report_date->toDateString())
            : [];

        // PRA segregation (owner 9 Aug 2026) — computed from the historical
        // transaction set (works for OLD closed days too; no schema change).
        $taxSplit = $this->dayCloseTaxSplit($transactions);

        // Per-stream split (Task 660): prefer the figures FROZEN at close time
        // (wash may have deleted reporting-OFF finals); graceful fallback for
        // OLD reports = best-effort recompute from surviving historical rows.
        $streamSplit = is_array($report->stream_summary ?? null)
            ? $report->stream_summary
            : $this->buildDayCloseStreamSplit($this->withLocalStreamRows($transactions, (int) $report->company_id, $report->report_date->toDateString(), null, $rptBranchId));

        // Task 705: Z display mode-gating — Local stream section renders only
        // in khufia local-check mode (or for LOCAL-scoped viewers).
        $showLocalStream = (bool) session('pos_local_check')
            || ((\Illuminate\Support\Facades\Auth::guard('pos')->user()?->posBillingScope() ?? 'both') === 'local');

        // Counter-wise cash reconciliation (Task 1375): frozen-first, same rule
        // as the stream split — an OLD report (or a box whose column has not
        // landed) falls back to a best-effort rebuild, and a counter-less shop
        // gets nothing so its PDF is byte-for-byte what it always was.
        $counterCash = $this->dayCloseCounterCash($report, $transactions, $rptBranchId);
        $counterCashTotals = $counterCash->isEmpty() ? null : \App\Services\PosCounterDrawer::totals($counterCash);
         $summary = $this->buildDayCloseSummary(
             $report,
             $streamSplit,
             $showLocalStream,
             $report,
             $counterCashTotals,
             $this->buildDayCloseSummaryPaymentSplit(
                 $transactions->filter(fn ($t) => ($t->transaction_type ?? 'sale') !== 'return'),
                 $transactions->filter(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')
             ),
             true
         );

        return $this->renderReportPdf(
             $isSummaryReport ? 'pos.day-close-summary-pdf' : 'pos.day-close-pdf',
             compact('company', 'report', 'transactions', 'cashierBreakdown', 'terminalBreakdown', 'analytics', 'hazri', 'bioPunches', 'taxSplit', 'streamSplit', 'showLocalStream', 'counterCash', 'counterCashTotals', 'summary', 'isSummaryReport'),
             ($isSummaryReport ? 'Summary-Z-Report-' : 'Day-Close-') . "{$report->report_number}-{$report->report_date->format('Y-m-d')}.pdf"
        );
    }

    /**
     * 80mm thermal Z-report (owner request Jul 2026): browser-printable summary
     * of a CLOSED day for cheap receipt printers — same historical data set as
     * the A4 PDF (archived bills included, local bills excluded).
     */
     public function dayCloseThermal($id, ?Request $request = null)
    {
        // Owner rule (5 Aug 2026): cashier day-close only via company switch / Custom Access tick.
        $dayCloseUser = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($dayCloseUser && !\App\Services\PosAccessService::dayCloseAllowed($dayCloseUser)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        // Task 1197: the stored Z-report is COMPANY-WIDE — an isolated cashier's
        // day-close access is preview-only, never the frozen shop document.
        if ($dayCloseUser && $dayCloseUser->posSalesIsolated()) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
         $request = $request ?: Request::create('/pos/day-close/' . $id . '/thermal', 'GET');
         $isSummaryReport = $this->dayCloseReportMode($request) === 'summary';
        $report = PosDayCloseReport::where('company_id', $companyId)->findOrFail($id);
        // Task 1360: a branch's Z-report stays that branch's document.
        if (!$this->canSeeBranchReport($report)) {
            return redirect()->route('pos.day-close')->with('error', __('pos.dayclose_other_branch_report'));
        }
        $rptBranchId = $this->reportBranchId($report);

        $transactions = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('business_date', $report->report_date->toDateString())
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            // Task 1360: same scope the report was frozen in (see the PDF path).
            ->where(fn ($q) => $this->scopeToBranch($q, $rptBranchId))
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        // Cashier figures are SIGNED (Task 570): refunds net revenue/tax;
        // counts stay sales-only.
        $cashierBreakdown = $transactions->groupBy(fn ($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            $sign = fn ($t) => ($t->transaction_type ?? 'sale') === 'return' ? -1 : 1;
            return (object) [
                'count' => $group->filter(fn ($t) => ($t->transaction_type ?? 'sale') !== 'return')->count(),
                'revenue' => $group->sum(fn ($t) => $sign($t) * (float) $t->total_amount),
                'tax' => $group->sum(fn ($t) => $sign($t) * (float) $t->tax_amount),
            ];
        });

        // Task 1349: counter-wise (terminal) breakdown — same signed convention.
        $terminalBreakdown = $this->buildTerminalBreakdown($transactions, $companyId);

        $analytics = $this->buildDayCloseAnalytics($companyId, $report->report_date->toDateString(), $transactions, $company, $rptBranchId);
        $hazri = PosFeatureService::planAllows($company, 'hazri_enabled')
            ? $this->buildHazriRows($companyId, $report->report_date->toDateString())
            : [];
        // Biometric punches — same plan gate as session hazri.
        $bioPunches = PosFeatureService::planAllows($company, 'hazri_enabled')
            ? $this->buildBiometricRows($companyId, $report->report_date->toDateString())
            : [];

        // PRA segregation (owner 9 Aug 2026) — same historical computation as the PDF.
        $taxSplit = $this->dayCloseTaxSplit($transactions);

        // Per-stream split (Task 660) — same frozen-first logic as the PDF.
        $streamSplit = is_array($report->stream_summary ?? null)
            ? $report->stream_summary
            : $this->buildDayCloseStreamSplit($this->withLocalStreamRows($transactions, (int) $report->company_id, $report->report_date->toDateString(), null, $rptBranchId));

        // Task 705: Z display mode-gating (same rule as the PDF).
        $showLocalStream = (bool) session('pos_local_check')
            || ((\Illuminate\Support\Facades\Auth::guard('pos')->user()?->posBillingScope() ?? 'both') === 'local');

        // Counter-wise cash reconciliation (Task 1375) — same frozen-first rule.
        $counterCash = $this->dayCloseCounterCash($report, $transactions, $rptBranchId);
        $counterCashTotals = $counterCash->isEmpty() ? null : \App\Services\PosCounterDrawer::totals($counterCash);
         $summary = $this->buildDayCloseSummary(
             $report,
             $streamSplit,
             $showLocalStream,
             $report,
             $counterCashTotals,
             $this->buildDayCloseSummaryPaymentSplit(
                 $transactions->filter(fn ($t) => ($t->transaction_type ?? 'sale') !== 'return'),
                 $transactions->filter(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')
             ),
             true
         );

         return view(
             $isSummaryReport ? 'pos.day-close-summary-thermal' : 'pos.day-close-thermal',
             compact('company', 'report', 'transactions', 'cashierBreakdown', 'terminalBreakdown', 'analytics', 'hazri', 'bioPunches', 'taxSplit', 'streamSplit', 'showLocalStream', 'counterCash', 'counterCashTotals', 'summary', 'isSummaryReport')
         );
    }

    /**
     * The counter-wise cash reconciliation of a CLOSED day for the printed
     * outputs (Task 1375). Prefers the snapshot frozen at close time — the wash
     * may have archived/deleted rows a live rebuild would miss — and falls back
     * to a best-effort rebuild for reports closed before this feature existed.
     */
    private function dayCloseCounterCash(PosDayCloseReport $report, $transactions, ?int $branchId): \Illuminate\Support\Collection
    {
        if (is_array($report->counter_summary ?? null)) {
            return collect($report->counter_summary);
        }

        try {
            return \App\Services\PosCounterDrawer::rows(
                (int) $report->company_id,
                $branchId,
                $report->report_date->toDateString(),
                $transactions,
                $this->buildRiderDayFigures((int) $report->company_id, $report->report_date->toDateString(), null, $branchId)
            );
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * Taxable vs exempt segregation for day-close surfaces (owner 9 Aug 2026):
     * taxable = subtotal − discount − exempt share (post-discount, PosTaxMath),
     * exempt = stored exempt_amount — the SAME formula the tax report uses,
     * so day-close and tax report never disagree.
     */
    private function dayCloseTaxSplit($transactions): array
    {
        return [
            'taxable' => $transactions->sum(fn ($t) => max(0, (float) $t->subtotal - (float) $t->discount_amount - (float) ($t->exempt_amount ?? 0))),
            'exempt' => $transactions->sum(fn ($t) => (float) ($t->exempt_amount ?? 0)),
        ];
    }

    /**
     * Core Z-report figure fields from an already-partitioned sale/return set
     * (Task 660): extracted from performDayClose so the X-Report renders the
     * EXACT same numbers a close would store — one code path, never diverges.
     * Counts stay SALES-only; money figures are netted (returns subtract);
     * cash/card/other via the ONE shared alias set (PosPaymentBuckets — 'card'
     * is stored as 'debit_card'; ='card' matching reported Rs 0 card sales on
     * the Z-report, live incident Jul 2026).
     */
    private function buildDayCloseFigureData($saleRows, $returnRows): array
    {
        $payBuckets = PosPaymentBuckets::split($saleRows);
        $refundBuckets = PosPaymentBuckets::split($returnRows);

        $data = [
            'total_invoices' => $saleRows->count(),
            'pra_invoices' => $saleRows->where('pra_status', 'submitted')->count(),
            'local_invoices' => $saleRows->whereIn('pra_status', ['local', null])->count(),
            'offline_invoices' => $saleRows->where('pra_status', 'offline')->count(),
            'gross_sales' => $saleRows->sum('subtotal') - $returnRows->sum('subtotal'),
            'total_discount' => $saleRows->sum('discount_amount') - $returnRows->sum('discount_amount'),
            'net_sales' => ($saleRows->sum('subtotal') - $returnRows->sum('subtotal'))
                - ($saleRows->sum('discount_amount') - $returnRows->sum('discount_amount')),
            'total_tax' => $saleRows->sum('tax_amount') - $returnRows->sum('tax_amount'),
            'total_amount' => $saleRows->sum('total_amount') - $returnRows->sum('total_amount'),
            'cash_amount' => round($payBuckets['cash'] - $refundBuckets['cash'], 2),
            'card_amount' => round($payBuckets['card'] - $refundBuckets['card'], 2),
            'other_amount' => round($payBuckets['other'] - $refundBuckets['other'], 2),
            'first_invoice_number' => $saleRows->first()->invoice_number ?? null,
            'last_invoice_number' => $saleRows->last()->invoice_number ?? null,
            'first_invoice_time' => $saleRows->first()->created_at ?? null,
            'last_invoice_time' => $saleRows->last()->created_at ?? null,
        ];

        // Returns detail on the Z-report (schema-guarded, drift self-heal).
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_close_reports', 'returns_count')) {
            $data['returns_count'] = $returnRows->count();
            $data['returns_amount'] = round((float) $returnRows->sum('total_amount'), 2);
        }

        // Wastage detail (Task 596): spoiled-goods returns — same is_wastage
        // filter the day-close SCREEN preview uses, so print matches screen.
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_close_reports', 'wastage_count')
            && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'is_wastage')) {
            $wastageRows = $returnRows->filter(fn ($t) => (bool) ($t->is_wastage ?? false));
            $data['wastage_count'] = $wastageRows->count();
            $data['wastage_amount'] = round((float) $wastageRows->sum('total_amount'), 2);
        }

        return $data;
    }

    /**
     * ═══ Per-stream day-close split (Task 660, ZFC owner feedback) ═══
     * PRA vs Local vs Exempt boxes — each stream's bill count, sale, tax and
     * cash/card/other payment buckets (returns NET their own stream), plus
     * the exempt detail: exempt value (incl. exempt shares on MIXED bills)
     * and which exempt items sold (qty + amount).
     *
     * Stream classifier mirrors PosTransaction::applyStreamTab within the
     * day-close PRA-mode set:
     *   exempt = pra_status 'exempt_internal' (all-exempt bills, Task 647)
     *   pra    = bill entered the PRA pipeline: any other non-'local'
     *            pra_status, OR a PRA fiscal number exists
     *   local  = the rest (pra_status NULL/'local' — reporting-OFF finals)
     *
     * 'summary' amounts use the SAME predicates as the stored count columns
     * (pra_invoices = submitted only, local_invoices = local/NULL, offline)
     * so the Invoice Summary table rows finally carry matching amounts.
     *
     * SHARED by the day-close page, performDayClose (frozen on the report),
     * the PDF/thermal fallback for OLD reports, and the X-Report — one code
     * path so the numbers can never diverge.
     */
    /**
     * Task 705: merge the COMPLETE local stream into a day-close transaction
     * set before building the per-stream split. The day-close figure sets
     * deliberately exclude invoice_mode='local' rows (L-series provisionals
     * and their returns), so the split's Local box was missing them. Merge is
     * for the SPLIT/STORED breakdown ONLY — the Z-report's own figures stay
     * computed from the PRA set, and PRA-reporting logic is untouched
     * (compliance boundary). Archived rows included (historical recomputes
     * after the wash); dedupe by id (LOCAL-scoped viewers' set already
     * contains these rows).
     */
    private function withLocalStreamRows($transactions, int $companyId, string $date, ?int $onlyCreatedBy = null, ?int $branchId = null)
    {
        try {
            $locals = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('business_date', $date)
                ->where('status', 'completed')
                ->where('invoice_mode', 'local')
                // Task 1197: an isolated cashier's PREVIEW merges only their
                // own local rows — otherwise the streamSplit boxes would leak
                // company-wide local counts/amounts around the filtered set.
                ->when($onlyCreatedBy, fn ($q) => $q->where('created_by', $onlyCreatedBy))
                // Task 1360: same for the branch being closed.
                ->where(fn ($q) => $this->scopeToBranch($q, $branchId))
                ->orderBy('created_at')
                ->get();
        } catch (\Throwable $e) {
            return $transactions; // schema drift — split falls back to the old set
        }
        if ($locals->isEmpty()) {
            return $transactions;
        }
        $seen = $transactions->pluck('id')->flip();

        return $transactions->concat($locals->reject(fn ($t) => isset($seen[$t->id])))->values();
    }

    private function buildDayCloseStreamSplit($transactions): array
    {
        $typeReady = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type');
        $returnRows = $typeReady
            ? $transactions->filter(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')->values()
            : collect();
        $saleRows = $typeReady
            ? $transactions->reject(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')->values()
            : $transactions;

        $streamOf = function ($t) {
            if (($t->pra_status ?? null) === PosTransaction::EXEMPT_INTERNAL) {
                return 'exempt';
            }
            if (!empty($t->pra_invoice_number)) {
                return 'pra';
            }
            $s = $t->pra_status ?? null;

            return ($s !== null && $s !== 'local') ? 'pra' : 'local';
        };

        $box = function ($sales, $returns) {
            $pb = PosPaymentBuckets::split($sales);
            $rb = PosPaymentBuckets::split($returns);

            return [
                'count' => $sales->count(),
                'sales' => round((float) $sales->sum('total_amount') - (float) $returns->sum('total_amount'), 2),
                'tax' => round((float) $sales->sum('tax_amount') - (float) $returns->sum('tax_amount'), 2),
                'cash' => round($pb['cash'] - $rb['cash'], 2),
                'card' => round($pb['card'] - $rb['card'], 2),
                'other' => round($pb['other'] - $rb['other'], 2),
            ];
        };

        $split = [];
        foreach (['pra', 'local', 'exempt'] as $stream) {
            $split[$stream] = $box(
                $saleRows->filter(fn ($t) => $streamOf($t) === $stream)->values(),
                $returnRows->filter(fn ($t) => $streamOf($t) === $stream)->values()
            );
        }

        // Exempt detail: value = stored exempt_amount (post-discount, PosTaxMath
        // — the same figure the tax report uses, covers exempt shares on mixed
        // bills too); items = which exempt items sold today (sales-only,
        // informational). Schema-guarded for older prod boxes.
        $exemptValue = round(
            (float) $saleRows->sum(fn ($t) => (float) ($t->exempt_amount ?? 0))
            - (float) $returnRows->sum(fn ($t) => (float) ($t->exempt_amount ?? 0)),
            2
        );
        $exemptItems = [];
        $saleIds = $saleRows->pluck('id')->filter()->all();
        if (!empty($saleIds) && \Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'is_tax_exempt')) {
            $exemptItems = \App\Models\PosTransactionItem::whereIn('transaction_id', $saleIds)
                ->where('is_tax_exempt', true)
                ->get(['item_name', 'quantity', 'subtotal'])
                ->groupBy('item_name')
                ->map(fn ($g, $name) => [
                    'name' => (string) $name,
                    'qty' => (float) $g->sum('quantity'),
                    'amount' => round((float) $g->sum('subtotal'), 2),
                ])
                ->sortByDesc('amount')
                ->take(20)
                ->values()
                ->all();
        }
        $split['exempt_detail'] = ['value' => $exemptValue, 'items' => $exemptItems];

        // Invoice Summary amounts — predicates MIRROR the stored count columns.
        $split['summary'] = [
            'pra_submitted' => round((float) $saleRows->where('pra_status', 'submitted')->sum('total_amount'), 2),
            'local' => round((float) $saleRows->whereIn('pra_status', ['local', null])->sum('total_amount'), 2),
            'offline' => round((float) $saleRows->where('pra_status', 'offline')->sum('total_amount'), 2),
        ];
         // Compact reports need the online bucket separately. Keep the existing
         // detailed cash/card/other split unchanged; this extra frozen snapshot
         // lets a later Z print stay truthful after the wash removes rows.
         $split['summary_payments'] = $this->buildDayCloseSummaryPaymentSplit($saleRows, $returnRows);

        return $split;
    }

     /**
      * Compact payment presentation: cash, card, online and remaining other.
      * The established PosPaymentBuckets definition remains authoritative for
      * the detailed report; this is only the additional compact presentation
      * bucket and is frozen inside stream_summary with the Z-report.
      */
     private function buildDayCloseSummaryPaymentSplit($saleRows, $returnRows): array
     {
         $onlineAliases = array_merge(
             \App\Support\PosPaymentLabels::ONLINE_ALIASES,
             ['bank_transfer']
         );
         $sums = ['cash' => 0.0, 'card' => 0.0, 'online' => 0.0, 'other' => 0.0];

         foreach ($saleRows as $transaction) {
             $method = strtolower(trim((string) ($transaction->payment_method ?? '')));
             $bucket = $method === 'cash'
                 ? 'cash'
                 : (in_array($method, \App\Support\PosPaymentBuckets::CARD_ALIASES, true)
                     ? 'card'
                     : (in_array($method, $onlineAliases, true) ? 'online' : 'other'));
             $sums[$bucket] += (float) ($transaction->total_amount ?? 0);
         }
         foreach ($returnRows as $transaction) {
             $method = strtolower(trim((string) ($transaction->payment_method ?? '')));
             $bucket = $method === 'cash'
                 ? 'cash'
                 : (in_array($method, \App\Support\PosPaymentBuckets::CARD_ALIASES, true)
                     ? 'card'
                     : (in_array($method, $onlineAliases, true) ? 'online' : 'other'));
             $sums[$bucket] -= (float) ($transaction->total_amount ?? 0);
         }

         return array_map(fn ($value) => round($value, 2), $sums);
     }

    /**
     * ═══ X-Report (Task 660, ZFC owner request) ═══
     * "Abhi tak ki report" — the SAME report family as the Z-Report but for a
     * day that is NOT closed yet. READ-ONLY by design: no wash/archive, no
     * integrity hash, no PosDayCloseReport row — live business-day figures
     * rendered through the SAME builders (buildDayCloseFigureData /
     * buildDayCloseStreamSplit / buildDayCloseAnalytics) so X and Z numbers
     * can never diverge. Access = dayCloseAllowed (admin/manager; cashier only
     * via company switch / Custom Access tick — same rule as day close).
     * Views receive $isXReport=true → PROVISIONAL watermark, hash hidden.
     */
    private function buildXReportContext(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($user && !\App\Services\PosAccessService::dayCloseAllowed($user)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $date = $request->get('date');
        try {
            $date = $date ? \Carbon\Carbon::parse($date)->toDateString() : \App\Services\PosBusinessDay::current($companyId);
        } catch (\Throwable $e) {
            $date = \App\Services\PosBusinessDay::current($companyId);
        }

        // Core precondition: X-Report exists ONLY for a still-open day. Once
        // the day is closed the Z-Report (frozen figures + hash) is the truth —
        // a direct URL hit here would print "PROVISIONAL — day not closed"
        // over a day that IS closed, from a live set the wash already thinned
        // (hide_archived hides archived bills). Hard-block, don't trust the UI.
        // whereDate, not where: the model's date cast writes 'Y-m-d H:i:s'
        // on drivers without a true DATE type (sqlite tests) — a strict
        // string match would silently let the guard through.
        // Task 1360: "closed" is per close scope — Main Shop's Z-report must not
        // block Gulberg's X-report (their day is still open).
        $xBranchId = $this->dayCloseBranchId();
        $alreadyClosed = PosDayCloseReport::where('company_id', $companyId)
            ->forBranch($xBranchId)
            ->whereDate('report_date', $date)
            ->exists();
        if ($alreadyClosed) {
            return redirect()->route('pos.day-close', ['date' => $date])
                ->with('error', __('pos.dayclose_report_exists'));
        }

        // Live PRA-mode set — hide_archived stays ACTIVE (X-Report is for the
        // still-open day; nothing has been washed yet). Local (non-PRA) bills
        // excluded exactly like the Z-report figure set.
        // Task 1197: X-Report is a PREVIEW print — an isolated cashier's copy
        // narrows to their own bills, same as the day-close preview page. The
        // stored Z-report (performDayClose) stays company-wide.
        $xIso = (bool) ($user?->posSalesIsolated() ?? false);
        $transactions = PosTransaction::where('company_id', $companyId)
            ->where('business_date', $date)
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->when($xIso, fn ($q) => $q->where('created_by', $user->id))
            // Task 1360: mid-day X must match the same drawer the Z will close.
            ->where(fn ($q) => $this->scopeToBranch($q, $xBranchId))
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        if ($transactions->isEmpty()) {
            return redirect()->route('pos.day-close', ['date' => $date])
                ->with('error', __('pos.no_transactions_for_date', ['date' => \Carbon\Carbon::parse($date)->format('d M Y')]));
        }

        $typeReady = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'transaction_type');
        $returnRows = $typeReady
            ? $transactions->filter(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')->values()
            : collect();
        $saleRows = $typeReady
            ? $transactions->reject(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')->values()
            : $transactions;

        // TRANSIENT report object — NEVER saved. Same field shapes the Z views
        // read, so both PDF/thermal templates work unchanged.
        $report = new PosDayCloseReport(array_merge(
            $this->buildDayCloseFigureData($saleRows, $returnRows),
            [
                'company_id' => $companyId,
                'report_date' => $date,
                'report_number' => 'X-' . \Carbon\Carbon::parse($date)->format('Ymd'),
            ]
        ));
        $report->created_at = now();

        // Live rider figures (informational — same shape the views read).
        // Task 1197: isolated cashier's X-report scopes rider figures + the
        // local-stream merge to their own bills, like the day-close preview.
        $riderFigures = $this->buildRiderDayFigures($companyId, $date, $xIso ? (int) $user->id : null, $xBranchId);
        if (!empty($riderFigures['active'])) {
            $report->rider_summary = $riderFigures;
        }

         $streamSplit = $this->buildDayCloseStreamSplit($this->withLocalStreamRows($transactions, $companyId, $date, $xIso ? (int) $user->id : null, $xBranchId));

        // Task 705: X display mode-gating (same rule as the Z page/PDF).
        $showLocalStream = (bool) session('pos_local_check')
            || (($user?->posBillingScope() ?? 'both') === 'local');

        // Cashier figures are SIGNED (Task 570): refunds net revenue/tax;
        // counts stay sales-only.
        $cashierBreakdown = $transactions->groupBy(fn ($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            $sign = fn ($t) => ($t->transaction_type ?? 'sale') === 'return' ? -1 : 1;

            return (object) [
                'count' => $group->filter(fn ($t) => ($t->transaction_type ?? 'sale') !== 'return')->count(),
                'revenue' => $group->sum(fn ($t) => $sign($t) * (float) $t->total_amount),
                'tax' => $group->sum(fn ($t) => $sign($t) * (float) $t->tax_amount),
            ];
        });

        // Task 1349: counter-wise (terminal) breakdown — same signed convention.
        $terminalBreakdown = $this->buildTerminalBreakdown($transactions, $companyId);

        $analytics = $this->buildDayCloseAnalytics($companyId, $date, $transactions, $company, $xBranchId);
        $hazri = PosFeatureService::planAllows($company, 'hazri_enabled')
            ? $this->buildHazriRows($companyId, $date)
            : [];
        $bioPunches = PosFeatureService::planAllows($company, 'hazri_enabled')
            ? $this->buildBiometricRows($companyId, $date)
            : [];
        $taxSplit = $this->dayCloseTaxSplit($transactions);
        $isXReport = true;
         $isSummaryReport = $this->dayCloseReportMode($request) === 'summary';
         $summary = $this->buildDayCloseSummary($report, $streamSplit, $showLocalStream, null, null, $this->buildDayCloseSummaryPaymentSplit($saleRows, $returnRows));
         $openingFloat = \App\Models\PosDayOpening::totalForDate($companyId, $date, $xBranchId);
         if ($openingFloat !== null) {
             $summary['cash_recon'] = [
                 'visible' => true,
                 'opening' => (float) $openingFloat,
                 'expected' => round((float) $openingFloat + (float) ($summary['payments']['cash'] ?? 0), 2),
                 'counted' => null,
                 'variance' => null,
             ];
         }

         return compact('company', 'report', 'transactions', 'cashierBreakdown', 'terminalBreakdown', 'analytics', 'hazri', 'bioPunches', 'taxSplit', 'streamSplit', 'showLocalStream', 'isXReport', 'isSummaryReport', 'summary');
    }

    /** X-Report as A4 PDF (Task 660) — read-only, PROVISIONAL watermark. */
    public function dayCloseXReportPdf(Request $request)
    {
        $ctx = $this->buildXReportContext($request);
        if (!is_array($ctx)) {
            return $ctx;
        }

        return $this->renderReportPdf(
             ($ctx['isSummaryReport'] ?? false) ? 'pos.day-close-summary-pdf' : 'pos.day-close-pdf',
            $ctx,
             (($ctx['isSummaryReport'] ?? false) ? 'Summary-X-Report-' : 'X-Report-') . $ctx['report']->report_date->format('Y-m-d') . '.pdf'
        );
    }

    /** X-Report on 80mm thermal (Task 660) — read-only, PROVISIONAL watermark. */
    public function dayCloseXReportThermal(Request $request)
    {
        $ctx = $this->buildXReportContext($request);
        if (!is_array($ctx)) {
            return $ctx;
        }

         return view(($ctx['isSummaryReport'] ?? false) ? 'pos.day-close-summary-thermal' : 'pos.day-close-thermal', $ctx);
    }

    /**
     * Summary X is the same read-only live report as detailed X, with a compact
     * presentation only. buildXReportContext owns the date, branch, isolated
     * cashier scope, and already-closed guard so figures cannot drift.
     */
    public function dayCloseXSummaryPdf(Request $request)
    {
        $ctx = $this->buildXReportContext($request);
        if (!is_array($ctx)) {
            return $ctx;
        }

        return $this->renderReportPdf(
            'pos.day-close-summary-pdf',
            $ctx,
            "Summary-X-Report-{$ctx['report']->report_date->format('Y-m-d')}.pdf"
        );
    }

    public function dayCloseXSummaryThermal(Request $request)
    {
        $ctx = $this->buildXReportContext($request);
        if (!is_array($ctx)) {
            return $ctx;
        }

        return view('pos.day-close-summary-thermal', $ctx);
    }

    /**
     * Minimal frozen Z-report context. Financial values come directly from the
     * stored day-close row; stream split is the already-frozen snapshot when
     * available. This report deliberately does not rebuild live totals after
     * the day-close wash has archived/deleted local bills.
     */
    private function buildSummaryZReportContext($id)
    {
        $dayCloseUser = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        if ($dayCloseUser && !\App\Services\PosAccessService::dayCloseAllowed($dayCloseUser)) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        if ($dayCloseUser && $dayCloseUser->posSalesIsolated()) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
        }

        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $report = PosDayCloseReport::where('company_id', $companyId)->findOrFail($id);
        if (!$this->canSeeBranchReport($report)) {
            return redirect()->route('pos.day-close')->with('error', __('pos.dayclose_other_branch_report'));
        }

        // Old reports predate stream_summary. Their reliable headline figures
        // remain available above; omit optional stream boxes rather than
        // reconstructing a washed historical data set.
        $streamSplit = is_array($report->stream_summary ?? null) ? $report->stream_summary : [];
        return compact('company', 'report', 'streamSplit');
    }

    public function dayCloseSummaryPdf($id)
    {
         return $this->dayCloseReportPdf(
             $id,
             Request::create('/pos/day-close/' . $id . '/summary/pdf', 'GET', ['report_mode' => 'summary'])
         );
    }

    public function dayCloseSummaryThermal($id)
    {
         return $this->dayCloseThermal(
             $id,
             Request::create('/pos/day-close/' . $id . '/summary/thermal', 'GET', ['report_mode' => 'summary'])
         );
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
        // Task 1360: opening cash is per branch now — show the drawer of the
        // branch on screen (the "all branches" view has none, and shows blank).
        $opening = \App\Models\PosDayOpening::forDate($companyId, $date, $this->dayCloseBranchId())?->loadMissing('enteredBy');

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

        // Task 1287: route through renderReportPdf so the 'ur' locale gets a
        // shaped Nastaleeq mPDF render (DomPDF can't shape Urdu; it stays the
        // en/rur path and the fallback via applyPdfSafeLocale inside).
        $filename = 'Payroll-Hazri-' . $dateFrom . '-to-' . $dateTo . '.pdf';
        return $this->renderReportPdf(
            'pos.reports-hazri-payroll-pdf',
            compact('company', 'dateFrom', 'dateTo', 'rangeRows', 'rangeBioRows'),
            $filename
        );
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
        return \App\Support\PosSessionHazriRows::build(
            $companyId,
            $date,
            // Bills of the SAME business day (historical truth — archived
            // rows included, matches the day-close data set).
            fn ($start, $end) => PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('business_date', $date)
                ->selectRaw('created_by, COUNT(*) as bill_count, MIN(created_at) as first_sale, MAX(created_at) as last_sale, SUM(total_amount) as revenue')
                ->groupBy('created_by')
                ->get()
                ->keyBy('created_by'),
            'hazri rows failed'
        );
    }

    /**
     * Biometric punch rows for one BUSINESS day (same 6 AM → 6 AM window).
     * Returns one row per staff member (or unmapped PIN) with first check-in,
     * last check-out, total punch count, and source (adms / csv_import).
     * Returns empty array when table is missing or on any error.
     */
    private function buildBiometricRows(int $companyId, string $date): array
    {
        return \App\Support\PosBiometricRows::build($companyId, $date);
    }
}
