<?php

namespace App\Services\Reporting;

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owner-authorized public OAuth client; no runner credentials or password grants. */
final class ReportingOAuth
{
    public function issuer(): string
    {
        $url = rtrim(config('app.url'), '/');
        $host = parse_url($url, PHP_URL_HOST);
        $local = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) && ! app()->environment('production');
        abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' || ($local && parse_url($url, PHP_URL_SCHEME) === 'http'), 503, 'Configure a canonical HTTPS APP_URL for reporting OAuth.');

        return $url;
    }

    public function resource(): string
    {
        return $this->issuer().'/api/reporting/mcp';
    }

    public function raw(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    public function validGrant(?object $grant): bool
    {
        return $grant && ! $grant->revoked_at && now()->lt($grant->expires_at)
            && $grant->resource === $this->resource()
            && $grant->scope === config('reporting_connector.scope')
            && AdminUser::find($grant->actor_id)?->isSuperAdmin();
    }

    public function authenticate(string $token): ?object
    {
        if (strlen($token) !== 43) {
            return null;
        }
        $access = DB::table('reporting_oauth_access_tokens')->where('hash', $this->hash($token))->first();
        if (! $access || now()->gte($access->expires_at)) {
            return null;
        }
        $grant = DB::table('reporting_oauth_grants')->where('id', $access->grant_id)->first();

        return $this->validGrant($grant) ? $grant : null;
    }

    public function tokens(object $grant): array
    {
        $access = $this->raw();
        $refresh = $this->raw();
        $expiry = min(now()->addMinutes(config('reporting_connector.access_minutes'))->timestamp, strtotime($grant->expires_at));
        DB::table('reporting_oauth_access_tokens')->insert(['hash' => $this->hash($access), 'grant_id' => $grant->id,
            'expires_at' => date('Y-m-d H:i:s', $expiry), 'created_at' => now()]);
        DB::table('reporting_oauth_refresh_tokens')->insert(['hash' => $this->hash($refresh), 'grant_id' => $grant->id,
            'expires_at' => $grant->expires_at, 'created_at' => now()]);

        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => max(0, $expiry - now()->timestamp),
            'refresh_token' => $refresh, 'scope' => $grant->scope];
    }

    public function exchange(array $input): ?array
    {
        if (($input['resource'] ?? '') !== $this->resource()) {
            return null;
        }

        return DB::transaction(function () use ($input) {
            if (($input['grant_type'] ?? '') === 'authorization_code') {
                $code = DB::table('reporting_oauth_codes')->where('hash', $this->hash($input['code'] ?? ''))->lockForUpdate()->first();
                $verifier = $input['code_verifier'] ?? '';
                if (! $code || $code->used_at || now()->gte($code->expires_at)
                    || ($input['client_id'] ?? '') !== $code->client_id || ($input['redirect_uri'] ?? '') !== $code->redirect_uri
                    || $code->resource !== $this->resource() || ! preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)
                    || ! hash_equals($code->challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))
                    || ! AdminUser::find($code->actor_id)?->isSuperAdmin()) {
                    return null;
                }
                DB::table('reporting_oauth_codes')->where('hash', $code->hash)->update(['used_at' => now()]);
                $grant = (object) ['id' => (string) Str::uuid(), 'client_id' => $code->client_id, 'actor_id' => $code->actor_id,
                    'resource' => $code->resource, 'scope' => $code->scope, 'company_ids' => $code->company_ids,
                    'expires_at' => now()->addDays(config('reporting_connector.grant_days'))->format('Y-m-d H:i:s'),
                    'revoked_at' => null, 'created_at' => now()];
                DB::table('reporting_oauth_grants')->insert((array) $grant);

                return $this->tokens($grant);
            }
            if (($input['grant_type'] ?? '') !== 'refresh_token') {
                return null;
            }
            $refresh = DB::table('reporting_oauth_refresh_tokens')->where('hash', $this->hash($input['refresh_token'] ?? ''))->lockForUpdate()->first();
            if (! $refresh) {
                return null;
            }
            $grant = DB::table('reporting_oauth_grants')->where('id', $refresh->grant_id)->lockForUpdate()->first();
            if (! $grant || ($input['client_id'] ?? '') !== $grant->client_id) {
                return null;
            }
            // Return instead of throwing: replay revocation must commit.
            if ($refresh->used_at) {
                DB::table('reporting_oauth_grants')->where('id', $grant->id)->update(['revoked_at' => now()]);

                return null;
            }
            if (! $this->validGrant($grant) || now()->gte($refresh->expires_at)
                || (isset($input['scope']) && $input['scope'] !== $grant->scope)) {
                return null;
            }
            DB::table('reporting_oauth_refresh_tokens')->where('hash', $refresh->hash)->update(['used_at' => now()]);

            return $this->tokens($grant);
        });
    }
}
