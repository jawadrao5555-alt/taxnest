<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Authenticated first-run exchange. No tenant credential travels in an installer or URL. */
final class DesktopAgentPairingController extends Controller
{
    private function context(string $panel): array
    {
        abort_unless(in_array($panel, ['pos', 'fbrpos'], true), 404);
        $user = auth($panel)->user();
        abort_unless($user && $user->is_active && $user->isPosAdmin(), 403);
        if ($panel === 'pos') {
            $access = \App\Services\PosAccessService::customSet($user);
            abort_unless($access === null || in_array('customize', $access, true), 403);
        }
        $company = Company::findOrFail($user->company_id);
        abort_unless($company->product_type === $panel, 403);
        abort_unless(!empty($company->agent_api_key) || PosFeatureService::planAllows($company, 'offline_enabled'), 403);
        return [$user, $company];
    }

    private function revision(Company $company): string
    {
        return hash('sha256', (string) $company->agent_api_key . '|' . (int) $company->agent_enabled);
    }

    public function preview(Request $request, string $panel)
    {
        [$user, $company] = $this->context($panel);
        $nonce = Str::random(64);
        // Only a digest is kept in the session. One outstanding confirmation per panel.
        $request->session()->put('desktop_pairing.' . $panel, [
            'hash' => hash('sha256', $nonce), 'expires' => now()->addMinutes(5)->timestamp,
            'user' => (string) $user->id, 'company' => (string) $company->id,
            'revision' => $this->revision($company),
        ]);
        return response()->json([
            'success' => true, 'nonce' => $nonce, 'company_id' => $company->id,
            'company_name' => $company->name, 'expires_in' => 300,
            'csrf_token' => $request->session()->token(),
            'agent_enabled' => (bool) $company->agent_enabled,
            // Allows an already configured same-company installation to remain untouched.
            'credential_digest' => hash('sha256', (string) $company->agent_api_key),
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function confirm(Request $request, string $panel)
    {
        [$user, $company] = $this->context($panel);
        $input = $request->validate(['nonce' => 'required|string|size:64', 'company_id' => 'required|integer|min:1']);
        // Routes serialize this session via block(): simultaneous confirms cannot replay.
        $claim = $request->session()->pull('desktop_pairing.' . $panel);
        abort_unless(is_array($claim)
            && ($claim['expires'] ?? 0) > now()->timestamp
            && ($claim['user'] ?? '') === (string) $user->id
            && ($claim['company'] ?? '') === (string) $company->id
            && (string) $input['company_id'] === (string) $company->id
            && hash_equals((string) ($claim['hash'] ?? ''), hash('sha256', $input['nonce'])), 409);

        $company = DB::transaction(function () use ($company, $claim, $panel) {
            $locked = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            // Rotation, disable/revoke and concurrent fresh pairing invalidate the old preview.
            abort_unless(($claim['expires'] ?? 0) > now()->timestamp
                && hash_equals((string) $claim['revision'], $this->revision($locked)), 409);
            abort_unless(!empty($locked->agent_api_key) || PosFeatureService::planAllows($locked, 'offline_enabled'), 403);
            $changes = ['agent_enabled' => true];
            if (empty($locked->agent_api_key)) {
                $changes['agent_api_key'] = 'tnk_' . Str::random(48);
            }
            // Match legacy PRA printing-only provisioning; never change existing explicit routing.
            if ($panel === 'pos' && Schema::hasColumn('companies', 'agent_submits_pra')
                && (empty($locked->agent_api_key) || (!$locked->agent_enabled && $locked->agent_submits_pra === null))) {
                $changes['agent_submits_pra'] = false;
            }
            $locked->update($changes); // Company saving hook maintains the key hash.
            return $locked->refresh();
        });
        return response()->json([
            'success' => true, 'server_url' => url('/api/agent'),
            'api_key' => $company->agent_api_key, 'company_id' => $company->id,
            'company_name' => $company->name,
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
