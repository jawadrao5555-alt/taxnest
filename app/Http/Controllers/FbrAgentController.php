<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * FBR POS — Desktop Agent page (Task 1403).
 *
 * FBR shops share the SAME Desktop Agent binary, company key and
 * /api/agent endpoint as PRA, but they reached it only through the
 * FBR Settings page, and only while fbr_connection_mode was
 * 'fiscal_device'. A cloud-mode FBR shop therefore had NO way to pair
 * an agent — which is what silent BILL + STORE SLIP printing needs.
 * Printing has nothing to do with how invoices reach FBR.
 *
 * So this controller owns an FBR-side agent page that is MODE-INDEPENDENT:
 *   • reachable from the FBR nav + Customize hub in cloud AND fiscal_device
 *   • minting/regenerating a key NEVER writes fbr_connection_mode,
 *     agent_submits_pra or anything else that decides where invoices go
 *     (that stays exclusively with FBR Settings)
 *   • the plan gate (pricing_plans.offline_enabled) and its upgrade
 *     message match PRA — locked shops see WHY, the page is not hidden
 *
 * PRA's /pos/agent page stays untouched: it additionally owns the PRA
 * submission-mode switch, which has no FBR counterpart.
 */
class FbrAgentController extends Controller
{
    /**
     * FBR company for this request. currentCompanyId is bound by FbrPosAuth;
     * the user fallback keeps direct/test invocation working.
     */
    private function company(): ?Company
    {
        $id = (int) (app()->bound('currentCompanyId') ? app('currentCompanyId') : 0);
        if ($id <= 0) {
            $id = (int) (Auth::guard('fbrpos')->user()->company_id ?? 0);
        }
        return $id > 0 ? Company::find($id) : null;
    }

    /**
     * Agent credentials are shop-wide secrets (anyone holding the key can
     * print for — and post jobs to — this company), so the page is
     * admin/manager-only, same bar as FBR Settings and Team.
     */
    private function adminGate(): bool
    {
        $u = Auth::guard('fbrpos')->user();
        return (bool) ($u && $u->isPosAdmin());
    }

    /** Business+ plan gate — identical column + semantics to PRA's page. */
    private function offlineAllowed(?Company $company): bool
    {
        return PosFeatureService::planAllows($company, 'offline_enabled');
    }

    public function show(Request $request)
    {
        abort_unless($this->adminGate(), 403, __('pos.only_company_admin_change_setting'));
        $company = $this->company();
        abort_unless($company, 404);

        // Print-job stats: the FBR agent's job here is PRINTING, so show what
        // the shop can act on instead of PRA's submission counters.
        $stats = ['queued' => 0, 'printed_today' => 0, 'failed_today' => 0];
        if (Schema::hasTable('pos_print_jobs')) {
            $base = fn () => DB::table('pos_print_jobs')->where('company_id', $company->id);
            $stats = [
                'queued'        => (clone $base())->whereIn('status', ['queued', 'claimed'])->count(),
                'printed_today' => (clone $base())->where('status', 'printed')->whereDate('updated_at', today())->count(),
                'failed_today'  => (clone $base())->where('status', 'failed')->whereDate('updated_at', today())->count(),
            ];
        }

        $isOnline = $company->agentOnline();
        $release  = app(AgentManagementController::class)->latestVersionInfo();

        $latestAgentVersion = null;
        if (!empty($release['tag']) && preg_match('/^v?(\d{1,2})\.(\d+)\.(\d+)$/', $release['tag'], $m)) {
            $latestAgentVersion = "{$m[1]}.{$m[2]}.{$m[3]}";
        }
        $agentOutdated = $latestAgentVersion && $company->agent_version
            && version_compare($company->agent_version, $latestAgentVersion, '<');

        $offlineAllowed = $this->offlineAllowed($company);

        // Honesty inputs for the "what still needs doing" strip.
        $printerSettings   = $company->printerSettings();
        $silentPrintOn     = (bool) ($printerSettings['silent_print_enabled'] ?? false);
        $storeSlipOn       = (bool) ($company->kitchen_printer_enabled ?? false);
        $reportedPrinters  = count($printerSettings['available_printers'] ?? []);

        return view('fbr-pos.agent', compact(
            'company', 'stats', 'isOnline', 'release', 'offlineAllowed',
            'latestAgentVersion', 'agentOutdated',
            'silentPrintOn', 'storeSlipOn', 'reportedPrinters'
        ));
    }

    /**
     * Mint the FIRST key for this shop.
     *
     * Writes ONLY the pairing columns. fbr_connection_mode is deliberately
     * absent: pairing an agent for printing must never move a cloud shop
     * onto the fiscal-device submission route (that silently changed where
     * every invoice went before Task 1403).
     */
    public function generateKey(Request $request)
    {
        abort_unless($this->adminGate(), 403, __('pos.only_company_admin_change_setting'));
        $company = $this->company();
        abort_unless($company, 404);

        if (!empty($company->agent_api_key)) {
            return back()->with('error', __('pos.fbr_agent_key_exists'));
        }

        if (!$this->offlineAllowed($company)) {
            return back()->with('error', __('pos.fbr_agent_plan_locked_msg'));
        }

        // Race-safe: only the first writer lands, everyone reads that key back
        // (two admins hitting Generate must not each get a different key).
        Company::whereKey($company->id)
            ->where(function ($q) {
                $q->whereNull('agent_api_key')->orWhere('agent_api_key', '');
            })
            ->update([
                'agent_api_key' => 'tnk_' . Str::random(48),
                'agent_enabled' => true,
            ]);

        return back()->with('success', __('pos.fbr_agent_key_generated'));
    }

    /**
     * Rotate the key. Same rule as generate: submission routing untouched.
     * Already-paired shops are grandfathered past the plan gate so a live
     * agent can always be re-paired (offline-first: printing never stranded).
     */
    public function regenerateKey(Request $request)
    {
        abort_unless($this->adminGate(), 403, __('pos.only_company_admin_change_setting'));
        $company = $this->company();
        abort_unless($company, 404);

        if (empty($company->agent_api_key) && !$this->offlineAllowed($company)) {
            return back()->with('error', __('pos.fbr_agent_plan_locked_msg'));
        }

        $company->update([
            'agent_api_key' => 'tnk_' . Str::random(48),
            'agent_enabled' => true,
        ]);

        return back()->with('success', __('pos.fbr_agent_key_regenerated'));
    }

    /**
     * Download the agent for an FBR session.
     *
     * The shared AgentManagementController::downloadAgent() resolves the
     * company from the POS guard only, so on FBR routes it found no user,
     * skipped the plan gate entirely and served the installer to Starter
     * shops that cannot pair it. Resolve the FBR company here and apply the
     * same gate + message PRA uses, then hand the asset lookup back to the
     * shared controller (one release source of truth).
     */
    public function download(Request $request)
    {
        $company = $this->company();
        if ($company && empty($company->agent_api_key) && !$this->offlineAllowed($company)) {
            return back()->with('error', __('pos.fbr_agent_plan_locked_msg'));
        }

        return app(AgentManagementController::class)->serveAgentAsset($request->query('type', 'exe'));
    }
}
