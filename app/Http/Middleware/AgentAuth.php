<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Company;
use App\Support\AgentApiKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Desktop-agent bearer auth (/api/agent/*).
 *
 * Security remediation (Sep 2026):
 *  - Lookup goes through companies.agent_api_key_hash (sha256) and is then
 *    re-verified with hash_equals() — no more SQL equality on the plaintext
 *    column. Rows that pre-date the hash migration (or were written by a
 *    path that bypassed the model hook) still authenticate via the legacy
 *    plaintext lookup ONCE and are healed by backfilling the hash.
 *  - Failed attempts (missing / unknown / disabled key) are counted per IP so
 *    a brute-force loop is slowed down with 429s. Successful requests never
 *    touch that counter, so a shop with many counters behind one NAT is not
 *    affected (their volume limit is the named 'agent-api' limiter, keyed by
 *    the presented key — see AppServiceProvider).
 */
class AgentAuth
{
    /** Failed authentications tolerated per IP per minute before a 429. */
    public const FAIL_LIMIT_PER_MINUTE = 60;

    /** How long a key that authenticated stays exempt from the per-IP fail block. */
    private const KNOWN_GOOD_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        $failKey = 'agent-auth-fail:' . sha1((string) $request->ip());
        $key = $request->bearerToken() ?: $request->header('X-Agent-Key');
        $keyId = $key ? sha1((string) $key) : null;

        // Over the per-IP fail budget: refuse BEFORE the DB lookup — unless
        // this exact key authenticated recently. A shop whose stale counter
        // keeps presenting a revoked key must not take its healthy counters
        // (same NAT) offline with it.
        if (RateLimiter::tooManyAttempts($failKey, self::FAIL_LIMIT_PER_MINUTE)
            && !($keyId && Cache::has('agent-auth-ok:' . $keyId))) {
            return $this->tooManyFailures($failKey);
        }

        if (!$key) {
            RateLimiter::hit($failKey, 60);
            return response()->json(['error' => 'Missing agent API key'], 401);
        }

        $company = $this->resolveCompany((string) $key);

        if (!$company) {
            RateLimiter::hit($failKey, 60);
            if (RateLimiter::tooManyAttempts($failKey, self::FAIL_LIMIT_PER_MINUTE)) {
                return $this->tooManyFailures($failKey);
            }
            return response()->json(['error' => 'Invalid or disabled agent key'], 401);
        }

        Cache::put('agent-auth-ok:' . $keyId, 1, self::KNOWN_GOOD_SECONDS);
        $request->attributes->set('agent_company', $company);

        return $next($request);
    }

    private function tooManyFailures(string $failKey): Response
    {
        return response()->json(['error' => 'Too many failed agent authentications. Retry later.'], 429, [
            'Retry-After' => (string) max(1, RateLimiter::availableIn($failKey)),
        ]);
    }

    private function resolveCompany(string $key): ?Company
    {
        $hash = AgentApiKey::hash($key);
        $hashColumn = AgentApiKey::hashColumnAvailable();

        $company = null;
        if ($hashColumn) {
            $company = Company::where('agent_api_key_hash', $hash)
                ->where('agent_enabled', true)
                ->first();
        }

        if (!$company) {
            // Legacy / un-migrated row: plaintext lookup, then heal the hash so
            // the next request takes the hashed path.
            $company = Company::where('agent_api_key', $key)
                ->where('agent_enabled', true)
                ->first();

            if ($company && $hashColumn && $company->getAttribute('agent_api_key_hash') !== $hash) {
                try {
                    DB::table('companies')->where('id', $company->id)->update(['agent_api_key_hash' => $hash]);
                } catch (\Throwable $e) {
                    // Never block an agent over a telemetry-grade write.
                }
                $company->setAttribute('agent_api_key_hash', $hash);
                $company->syncOriginalAttribute('agent_api_key_hash');
            }
        }

        if (!$company) {
            return null;
        }

        // Defence in depth: the SQL match found a candidate; prove possession
        // of the full key in constant time before trusting the row.
        $stored = $hashColumn
            ? (string) $company->getAttribute('agent_api_key_hash')
            : AgentApiKey::hash((string) $company->getAttribute('agent_api_key'));

        if ($stored === '' || !hash_equals($stored, $hash)) {
            return null;
        }

        return $company;
    }
}
