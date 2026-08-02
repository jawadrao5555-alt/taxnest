<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Company;

class AgentManagementController extends Controller
{
    private function posUser()
    {
        return auth('pos')->user();
    }

    /**
     * Task 117 (Aug 2026): Offline billing + Desktop App is a Business+
     * feature (pricing_plans.offline_enabled plan gate). Starter shops must
     * not START a new agent pairing — but shops that already paired are
     * GRANDFATHERED (existing agent keeps auth/heartbeat so pending bills
     * and silent printing are never stranded — offline-first rule: bills
     * kabhi reject na hon).
     */
    private function offlineAllowed(Company $company): bool
    {
        return \App\Services\PosFeatureService::planAllows($company, 'offline_enabled');
    }

    public function show(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user, 403, 'POS authentication required.');
        $company = Company::findOrFail($user->company_id);

        $stats = [
            'pending' => \DB::table('pos_transactions')
                ->where('company_id', $company->id)
                ->whereIn('pra_status', ['offline', 'pending', 'failed'])
                ->whereNull('pra_invoice_number')
                ->count(),
            'submitted_today' => \DB::table('pos_transactions')
                ->where('company_id', $company->id)
                ->where('pra_status', 'submitted')
                ->whereDate('updated_at', today())
                ->count(),
            'failed_today' => \DB::table('pos_transactions')
                ->where('company_id', $company->id)
                ->where('pra_status', 'failed')
                ->whereDate('updated_at', today())
                ->count(),
        ];

        $isOnline = $company->agent_last_seen
            && \Carbon\Carbon::parse($company->agent_last_seen)->gt(now()->subMinutes(2));

        $release = $this->latestVersionInfo();
        $offlineAllowed = $this->offlineAllowed($company);

        return view('company.agent', compact('company', 'stats', 'isOnline', 'release', 'offlineAllowed'));
    }

    /**
     * NestPOS Desktop auto-config (Jul 2026): the Electron shell calls this
     * right after a successful POS login and feeds the company's agent
     * credentials into itself — zero manual setup for silent printing.
     *
     * SAFETY: this endpoint must NEVER change how PRA submission is routed.
     * - Fresh key: agent_enabled=true + agent_submits_pra=false (printing-only;
     *   fiscal_device companies route via agent regardless — agentHandlesPra()).
     * - Existing key but agent disabled: re-enable, and if agent_submits_pra is
     *   NULL pin it to false first (NULL + enabled would flip PRA routing to
     *   the agent via the legacy `?? true` fallback in agentHandlesPra()).
     * - Existing enabled key: returned as-is, nothing written.
     */
    public function desktopConfig(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user, 403);
        $company = Company::findOrFail($user->company_id);

        $hasSubmitsCol = \Schema::hasColumn('companies', 'agent_submits_pra');

        // Task 117: NEW pairing is plan-gated. Already-paired companies
        // (existing key) are grandfathered below — never break a live agent.
        if (empty($company->agent_api_key) && !$this->offlineAllowed($company)) {
            return response()->json([
                'success' => false,
                'plan_locked' => true,
                'message' => 'Desktop App aap ke mojooda package mein shamil nahi — Business ya us se upar ke package par upgrade karein.',
            ], 403);
        }

        if (empty($company->agent_api_key)) {
            // Race-safe key generation (architect, GA-prep): two simultaneous
            // desktopConfig calls must not each write a different key (last
            // write wins = one agent gets a dead key). Conditional UPDATE —
            // only the FIRST writer lands; everyone reads the winning key back.
            $update = [
                'agent_api_key' => 'tnk_' . Str::random(48),
                'agent_enabled' => true,
            ];
            if ($hasSubmitsCol) {
                $update['agent_submits_pra'] = false;
            }
            Company::whereKey($company->id)
                ->where(function ($q) {
                    $q->whereNull('agent_api_key')->orWhere('agent_api_key', '');
                })
                ->update($update);
            $company->refresh();
        } elseif (!$company->agent_enabled) {
            $update = ['agent_enabled' => true];
            if ($hasSubmitsCol && $company->agent_submits_pra === null) {
                $update['agent_submits_pra'] = false;
            }
            $company->update($update);
        }

        return response()->json([
            'success' => true,
            'server_url' => url('/api/agent'),
            'api_key' => $company->agent_api_key,
            'company_id' => $company->id,
            'company_name' => $company->name,
        ]);
    }

    public function generateKey(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user, 403);
        $company = Company::findOrFail($user->company_id);

        // Task 117: fresh pairing is plan-gated (Business+).
        if (empty($company->agent_api_key) && !$this->offlineAllowed($company)) {
            return back()->with('error', 'Desktop App aap ke mojooda package mein shamil nahi — Business ya us se upar ke package par upgrade karein.');
        }

        $company->update([
            'agent_api_key' => 'tnk_' . Str::random(48),
            'agent_enabled' => true,
        ]);

        return back()->with('success', 'Agent API key generated successfully.');
    }

    public function regenerateKey(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user, 403);
        $company = Company::findOrFail($user->company_id);

        $company->update([
            'agent_api_key' => 'tnk_' . Str::random(48),
        ]);

        return back()->with('success', 'Agent API key regenerated. Update your installed agent.');
    }

    /**
     * Flip the PRA SUBMISSION mode: Agent Sync ⇄ Direct Production.
     *
     * DECOUPLED (owner issue, 23 Jul 2026): this used to flip agent_enabled, which
     * also killed agent auth + SILENT PRINTING. Now it flips agent_submits_pra only —
     * switching to Direct Production keeps the agent connected so silent receipt/KOT
     * printing continues to work.
     */
    public function toggle(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user, 403);
        $company = Company::findOrFail($user->company_id);

        $toAgentSync = !$company->agentHandlesPra();

        if (!$toAgentSync && ($company->pra_connection_mode ?? 'cloud') === 'fiscal_device') {
            return back()->with('error', 'Fiscal Device mode mein Direct Production available nahi (PRA Code 112) — submission Desktop Agent ke zariye hi hoti hai. Pehle PRA Settings par Connection Mode change karein.');
        }

        // Task 117: switching TO Agent Sync mints a key = new pairing; plan-gated
        // unless the company is already paired (grandfathered).
        if ($toAgentSync && empty($company->agent_api_key) && !$this->offlineAllowed($company)) {
            return back()->with('error', 'Desktop App / Agent Sync aap ke mojooda package mein shamil nahi — Business ya us se upar ke package par upgrade karein.');
        }

        if ($toAgentSync) {
            $company->update([
                'agent_submits_pra' => true,
                'agent_enabled' => true,
                'agent_api_key' => $company->agent_api_key ?: ('tnk_' . Str::random(48)),
            ]);
            return back()->with('success', 'Agent Sync mode enabled — desktop agent ab PRA submission karega.');
        }

        $company->update([
            'agent_submits_pra' => false,
        ]);

        return back()->with('success', 'Direct Production mode enabled — server ab PRA pe directly submit karega. Agent connected rahega (silent printing chalti rahegi).');
    }

    /**
     * Latest GitHub release info (tag + assets), cached 10 minutes.
     * Shared by the download redirect, the /pos/agent page AND the agent
     * heartbeat's self-update advertisement (AgentController).
     */
    public static function latestReleaseInfo(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('taxnest_agent_latest_release', 600, function () {
            try {
                $resp = \Illuminate\Support\Facades\Http::timeout(6)
                    ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'TaxNest'])
                    ->get('https://api.github.com/repos/jawadrao5555-alt/taxnest/releases/latest');
                if ($resp->successful()) {
                    return [
                        'tag' => $resp->json('tag_name'),
                        'assets' => collect($resp->json('assets', []))->map(fn($a) => [
                            'name' => $a['name'],
                            'url' => $a['browser_download_url'],
                            'size' => $a['size'] ?? 0,
                        ])->values()->all(),
                    ];
                }
            } catch (\Throwable $e) {}
            return ['tag' => null, 'assets' => []];
        });
    }

    public function downloadAgent(\Illuminate\Http\Request $request)
    {
        // Task 117: agent download is plan-gated for un-paired companies.
        // Already-paired shops keep downloading (reinstall/update — grandfathered).
        $user = $this->posUser();
        if ($user) {
            $company = Company::find($user->company_id);
            if ($company && empty($company->agent_api_key) && !$this->offlineAllowed($company)) {
                return back()->with('error', 'Desktop App aap ke mojooda package mein shamil nahi — Business ya us se upar ke package par upgrade karein.');
            }
        }

        $type = $request->query('type', 'exe');

        $assets = self::latestReleaseInfo();

        $needle = $type === 'zip' ? '.zip' : '.exe';
        // Prefer the LARGEST matching asset — the real full installer, not a stale 0.2 MB stub.
        $asset = collect($assets['assets'])
            ->filter(fn($a) => str_ends_with(strtolower($a['name']), $needle))
            ->sortByDesc('size')
            ->first();

        if ($asset) {
            return redirect()->away($asset['url']);
        }

        $localPath = public_path('downloads/TaxNest-PRA-Agent-Windows.zip');
        if (file_exists($localPath)) {
            return response()->download($localPath, 'TaxNest-PRA-Agent-Windows.zip');
        }

        return redirect()->away('https://github.com/jawadrao5555-alt/taxnest/releases/latest');
    }

    public function latestVersionInfo()
    {
        $info = self::latestReleaseInfo();

        $exe = collect($info['assets'])->filter(fn($a) => str_ends_with(strtolower($a['name']), '.exe'))->sortByDesc('size')->first();
        $zip = collect($info['assets'])->filter(fn($a) => str_ends_with(strtolower($a['name']), '.zip'))->sortByDesc('size')->first();

        return [
            'tag' => $info['tag'],
            'has_exe' => (bool) $exe,
            'has_zip' => (bool) $zip,
            'exe_size_mb' => $exe ? round($exe['size'] / 1024 / 1024, 1) : null,
            'zip_size_mb' => $zip ? round($zip['size'] / 1024 / 1024, 1) : null,
        ];
    }
}
