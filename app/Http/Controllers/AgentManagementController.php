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

        return view('company.agent', compact('company', 'stats', 'isOnline', 'release'));
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

        if (empty($company->agent_api_key)) {
            $update = [
                'agent_api_key' => 'tnk_' . Str::random(48),
                'agent_enabled' => true,
            ];
            if ($hasSubmitsCol) {
                $update['agent_submits_pra'] = false;
            }
            $company->update($update);
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
