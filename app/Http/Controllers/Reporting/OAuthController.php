<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportingOAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OAuthController extends Controller
{
    public function __construct(private ReportingOAuth $oauth) {}

    private function owner(): object
    {
        $owner = auth('admin')->user();
        abort_unless($owner?->isSuperAdmin(), 403);

        return $owner;
    }

    public function resource()
    {
        return response()->json(['resource' => $this->oauth->resource(), 'authorization_servers' => [$this->oauth->issuer()],
            'scopes_supported' => [config('reporting_connector.scope')]]);
    }

    public function metadata()
    {
        $base = $this->oauth->issuer();

        return response()->json(['issuer' => $base, 'authorization_endpoint' => $base.'/admin/reporting/oauth/authorize',
            'token_endpoint' => $base.'/api/reporting/oauth/token', 'registration_endpoint' => $base.'/api/reporting/oauth/register',
            'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'], 'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [config('reporting_connector.scope')], 'authorization_response_iss_parameter_supported' => true]);
    }

    public function register(Request $request)
    {
        $v = $request->validate(['client_name' => 'nullable|string|max:100', 'redirect_uris' => 'required|array|min:1|max:3',
            'redirect_uris.*' => 'required|string|max:512', 'token_endpoint_auth_method' => 'nullable|in:none',
            'grant_types' => 'nullable|array', 'grant_types.*' => 'in:authorization_code,refresh_token',
            'response_types' => 'nullable|array', 'response_types.*' => 'in:code']);
        foreach ($v['redirect_uris'] as $uri) {
            if (! in_array($uri, config('reporting_connector.redirect_uris'), true)) {
                return response()->json(['error' => 'invalid_redirect_uri'], 400);
            }
        }
        $id = $this->oauth->raw();
        DB::table('reporting_oauth_clients')->insert(['id' => $id, 'name' => ($v['client_name'] ?? 'Read-only reporting client'), 'redirect_uris' => json_encode($v['redirect_uris']), 'created_at' => now()]);

        return response()->json(['client_id' => $id, 'client_name' => ($v['client_name'] ?? 'Read-only reporting client'), 'redirect_uris' => $v['redirect_uris'],
            'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']], 201);
    }

    private function authorization(Request $request): array
    {
        $v = $request->validate(['client_id' => 'required|string|max:64', 'redirect_uri' => 'required|string|max:512',
            'response_type' => 'required|in:code', 'code_challenge' => 'required|regex:/^[A-Za-z0-9_-]{43}$/D',
            'code_challenge_method' => 'required|in:S256', 'resource' => 'required|string|max:512',
            'scope' => 'required|string|max:100', 'state' => 'required|string|max:512']);
        $client = DB::table('reporting_oauth_clients')->where('id', $v['client_id'])->first();
        abort_unless($client && in_array($v['redirect_uri'], json_decode($client->redirect_uris, true), true)
            && in_array($v['redirect_uri'], config('reporting_connector.redirect_uris'), true)
            && $v['resource'] === $this->oauth->resource() && $v['scope'] === config('reporting_connector.scope'), 400);

        return [$v, $client];
    }

    public function consent(Request $request)
    {
        $this->owner();
        [$v,$client] = $this->authorization($request);
        $companies = DB::table('companies')->whereIn('product_type', ['pos', 'fbrpos', 'di']);
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'deleted_at')) {
            $companies->whereNull('deleted_at');
        }

        return view('admin.reporting.consent', ['parameters' => $v, 'client' => $client, 'companies' => $companies->orderBy('name')->get(['id', 'name', 'product_type'])]);
    }

    public function approve(Request $request)
    {
        $owner = $this->owner();
        [$v,$client] = $this->authorization($request);
        $choice = $request->validate(['decision' => 'required|in:approve,deny', 'company_scope' => 'required|in:selected,all',
            'company_ids' => 'nullable|array|max:500', 'company_ids.*' => 'integer|min:1|distinct']);
        $params = ['state' => $v['state'], 'iss' => $this->oauth->issuer()];
        if ($choice['decision'] === 'deny') {
            $params['error'] = 'access_denied';
        } else {
            $ids = null;
            if ($choice['company_scope'] === 'selected') {
                $ids = array_map('intval', $choice['company_ids'] ?? []);
                abort_unless(count($ids) > 0, 422);
                $q = DB::table('companies')->whereIn('id', $ids)->whereIn('product_type', ['pos', 'fbrpos', 'di']);
                if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'deleted_at')) {
                    $q->whereNull('deleted_at');
                }
                abort_unless($q->count() === count($ids), 422);
            }
            $code = $this->oauth->raw();
            DB::table('reporting_oauth_codes')->insert(['hash' => $this->oauth->hash($code), 'client_id' => $client->id, 'actor_id' => $owner->id,
                'redirect_uri' => $v['redirect_uri'], 'challenge' => $v['code_challenge'], 'resource' => $v['resource'], 'scope' => $v['scope'],
                'company_ids' => $ids === null ? null : json_encode($ids), 'expires_at' => now()->addMinutes(5), 'created_at' => now()]);
            $params['code'] = $code;
        }

        return redirect()->away($v['redirect_uri'].(str_contains($v['redirect_uri'], '?') ? '&' : '?').http_build_query($params));
    }

    public function token(Request $request)
    {
        $v = $request->validate(['grant_type' => 'required|in:authorization_code,refresh_token', 'client_id' => 'required|string|max:64',
            'resource' => 'required|string|max:512', 'code' => 'nullable|string|max:128', 'code_verifier' => 'nullable|string|max:128',
            'redirect_uri' => 'nullable|string|max:512', 'refresh_token' => 'nullable|string|max:128', 'scope' => 'nullable|string|max:100']);
        $result = $this->oauth->exchange($v);

        return response()->json($result ?? ['error' => 'invalid_grant'], $result ? 200 : 400)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    public function connections()
    {
        $owner = $this->owner();

        return view('admin.reporting.connections', ['resource' => $this->oauth->resource(),
            'grants' => DB::table('reporting_oauth_grants as g')->join('reporting_oauth_clients as c', 'c.id', '=', 'g.client_id')
                ->where('g.actor_id', $owner->id)->orderByDesc('g.created_at')->limit(100)->get(['g.id', 'c.name', 'g.company_ids', 'g.expires_at', 'g.revoked_at', 'g.created_at'])]);
    }

    public function revoke(Request $request, string $id)
    {
        $owner = $this->owner();
        $updated = DB::table('reporting_oauth_grants')->where('id', $id)->where('actor_id', $owner->id)->update(['revoked_at' => now()]);
        abort_unless($updated, 404);

        return redirect('/admin/reporting/connections')->with('status', 'Connection revoked.');
    }
}
