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

    public function toggle(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user, 403);
        $company = Company::findOrFail($user->company_id);

        $company->update([
            'agent_enabled' => !$company->agent_enabled,
        ]);

        return back()->with('success', $company->agent_enabled
            ? 'Agent enabled.'
            : 'Agent disabled.');
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
