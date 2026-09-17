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

        // Canonical liveness check (Task 1062) — same verdict as the
        // silent-print gate and every other surface.
        $isOnline = $company->agentOnline();

        $release = $this->latestVersionInfo();

        // Task 1062: version + self-update visibility. Outdated = a valid
        // agent-semver release tag is newer than the running agent's version.
        $latestAgentVersion = null;
        if (!empty($release['tag']) && preg_match('/^v?(\d{1,2})\.(\d+)\.(\d+)$/', $release['tag'], $m)) {
            $latestAgentVersion = "{$m[1]}.{$m[2]}.{$m[3]}";
        }
        $agentOutdated = $latestAgentVersion && $company->agent_version
            && version_compare($company->agent_version, $latestAgentVersion, '<');
        $offlineAllowed = $this->offlineAllowed($company);

        $canManageLocalCore = !$user->isPosCashier()
            && !in_array($user->pos_role ?? null, ['archive_viewer', 'local_viewer'], true);

        return view('company.agent', compact('company', 'stats', 'isOnline', 'release', 'offlineAllowed', 'latestAgentVersion', 'agentOutdated', 'canManageLocalCore'));
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
            $newKey = 'tnk_' . Str::random(48);
            $update = [
                'agent_api_key' => $newKey,
                'agent_enabled' => true,
            ];
            // Query-builder update bypasses the model's saving hook — mirror
            // the sha256 hash explicitly so AgentAuth can find the key.
            if (\App\Support\AgentApiKey::hashColumnAvailable()) {
                $update['agent_api_key_hash'] = \App\Support\AgentApiKey::hash($newKey);
            }
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

    /** Owner/admin opt-in only; Core must never be implicitly enabled by pairing. */
    public function toggleLocalCore(Request $request)
    {
        $user = $this->posUser();
        abort_unless($user && !$user->isPosCashier()
            && !in_array($user->pos_role ?? null, ['archive_viewer', 'local_viewer'], true), 403);
        abort_unless(\Schema::hasColumn('companies', 'agent_core_enabled'), 503, 'Local Core is not available yet.');

        $company = Company::findOrFail($user->company_id);
        $company->update(['agent_core_enabled' => !$company->agent_core_enabled]);

        return back()->with('success', $company->agent_core_enabled
            ? 'Local TaxNest Core enabled. Your compatible desktop agent can now discover it.'
            : 'Local TaxNest Core disabled. Existing inbox records were retained.');
    }

    /**
     * Latest GitHub release info (tag + assets), cached 10 minutes.
     * Shared by the download redirect, the /pos/agent page AND the agent
     * heartbeat's self-update advertisement (AgentController).
     */
    /**
     * GitHub repo (owner/name) that hosts agent release assets.
     * Aug 2026: moving to a public releases-only repo (nestpos-releases) so
     * the main source repo can go private. Overridable via SystemSetting
     * 'agent_release_repo' without a deploy.
     */
    public static function releaseRepo(): string
    {
        try {
            $repo = trim((string) \App\Models\SystemSetting::get('agent_release_repo', ''));
        } catch (\Throwable $e) {
            $repo = '';
        }
        // Basic owner/name sanity guard — anything else falls back to default.
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            return 'jawadrao5555-alt/nestpos-releases';
        }
        return $repo;
    }

    public static function latestReleaseInfo(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('taxnest_agent_latest_release', 600, function () {
            $candidates = [];

            // Release assets historically lived in both the public
            // releases-only repository and this repository. A release API
            // listing is only an inventory, not integrity provenance: every
            // candidate must carry a validated canonical manifest. Do not
            // fall back to a smaller/older release if the newest is malformed;
            // a shop needs an explicit unavailable result, not a fake update.
            foreach (array_unique([self::releaseRepo(), 'jawadrao5555-alt/taxnest']) as $repo) {
                try {
                    $resp = \Illuminate\Support\Facades\Http::timeout(6)
                        ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'TaxNest'])
                        ->get('https://api.github.com/repos/' . $repo . '/releases/latest');
                    $tag = (string) $resp->json('tag_name', '');
                    $version = \App\Services\AgentReleaseManifest::versionFromTag($tag);
                    if (!$resp->successful() || $version === null) {
                        continue;
                    }
                    $assets = collect($resp->json('assets', []))->map(fn($a) => [
                        'name' => $a['name'] ?? null,
                        'url' => $a['browser_download_url'] ?? null,
                        'size' => isset($a['size']) ? (int) $a['size'] : -1,
                        'digest' => $a['digest'] ?? null,
                    ])->values()->all();
                    $manifestAssets = array_values(array_filter($assets, fn ($asset) => ($asset['name'] ?? null) === \App\Services\AgentReleaseManifest::ASSET_NAME));
                    $manifest = null;
                    $reason = count($manifestAssets) === 1 ? 'manifest_invalid' : 'manifest_missing_or_ambiguous';
                    if (count($manifestAssets) === 1) {
                        $manifestResponse = \Illuminate\Support\Facades\Http::timeout(6)
                            ->withHeaders(['Accept' => 'application/json', 'User-Agent' => 'TaxNest'])
                            ->get($manifestAssets[0]['url']);
                        if ($manifestResponse->successful() && is_array($manifestResponse->json())) {
                            $manifest = $manifestResponse->json();
                        } else {
                            $reason = 'manifest_unreadable';
                        }
                    }
                    $validated = is_array($manifest)
                        ? \App\Services\AgentReleaseManifest::validate($manifest, $tag, $repo, $assets)
                        : null;
                    $candidates[] = [
                        'repo' => $repo,
                        'tag' => $tag,
                        'version' => $version,
                        'validated' => $validated,
                        'reason' => $validated ? null : $reason,
                    ];
                } catch (\Throwable $e) {
                    // Never advertise an unchecked mirror when the release
                    // host/API is unavailable.
                }
            }

            if ($candidates === []) {
                return ['tag' => null, 'assets' => [], 'available' => false, 'reason' => 'release_unavailable'];
            }
            usort($candidates, fn ($a, $b) => version_compare($b['version'], $a['version']));
            $newestVersion = $candidates[0]['version'];
            $newest = array_values(array_filter($candidates, fn ($candidate) => $candidate['version'] === $newestVersion));
            $valid = array_values(array_filter($newest, fn ($candidate) => is_array($candidate['validated'])));
            if ($valid === []) {
                return [
                    'tag' => $newest[0]['tag'],
                    'assets' => [],
                    'available' => false,
                    'reason' => $newest[0]['reason'] ?? 'manifest_invalid',
                ];
            }

            $release = $valid[0]['validated'];
            // Retain a validated matching legacy mirror only. Agent <1.7.0
            // pins this hostname, so an unchecked URL rewrite would bypass
            // the manifest control exactly where backward compatibility is
            // most fragile.
            $legacy = collect($valid)->first(fn ($candidate) => $candidate['repo'] === 'jawadrao5555-alt/taxnest');
            if ($legacy) {
                $release['legacy_zip'] = $legacy['validated']['zip'];
            }
            $release['available'] = true;
            $release['reason'] = null;
            return $release;
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

        return $this->serveAgentAsset($request->query('type', 'exe'));
    }

    /**
     * Release-asset lookup + redirect, WITHOUT any panel/plan gate.
     *
     * Split out of downloadAgent() so FbrAgentController can apply the FBR
     * panel's own company resolution + plan gate and still share one release
     * source of truth (Task 1403).
     */
    public function serveAgentAsset(?string $type = 'exe')
    {
        $release = self::latestReleaseInfo();
        if (!($release['available'] ?? false)) {
            return response()->json([
                'error' => 'Agent release is temporarily unavailable because its canonical manifest could not be verified.',
                'reason' => $release['reason'] ?? 'manifest_invalid',
            ], 503);
        }
        $asset = $type === 'zip' ? ($release['zip'] ?? null) : ($release['exe'] ?? null);

        if ($asset) {
            return redirect()->away($asset['url']);
        }

        return response()->json(['error' => 'Requested canonical Agent asset is unavailable.'], 503);
    }

    public function latestVersionInfo()
    {
        $info = self::latestReleaseInfo();

        $exe = $info['exe'] ?? null;
        $zip = $info['zip'] ?? null;

        return [
            'tag' => $info['tag'],
            'available' => (bool) ($info['available'] ?? false),
            'reason' => $info['reason'] ?? null,
            'has_exe' => (bool) $exe,
            'has_zip' => (bool) $zip,
            'exe_size_mb' => $exe ? round($exe['size'] / 1024 / 1024, 1) : null,
            'zip_size_mb' => $zip ? round($zip['size'] / 1024 / 1024, 1) : null,
        ];
    }
}
