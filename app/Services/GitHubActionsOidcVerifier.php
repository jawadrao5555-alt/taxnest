<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jose\Component\Core\JWK;
use Jose\Component\Core\Util\RSAKey;

class GitHubActionsOidcVerifier
{
    public function verify(Request $request, string $expectedWorkflow): array
    {
        $token = preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization'));
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            abort(401, 'GitHub Actions OIDC token required.');
        }

        $header = $this->decodeJsonSegment($parts[0]);
        $claims = $this->decodeJsonSegment($parts[1]);
        $signature = $this->decodeSegment($parts[2]);

        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)) {
            abort(401, 'Unsupported OIDC token.');
        }

        $key = $this->signingKey($header['kid']);
        // jwt-library 4.x exposes the public key constructor directly; the
        // removed createFromValues helper would otherwise make every valid
        // GitHub token fail with an Error before signature verification.
        $pem = RSAKey::createFromJWK(new JWK($key))->toPEM();

        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            abort(401, 'Invalid OIDC signature.');
        }

        $now = time();
        $audiences = (array) ($claims['aud'] ?? []);
        $allowedWorkflow = config('deployment_approval.allowed_workflow_refs')[$expectedWorkflow] ?? null;

        if (
            !is_string($allowedWorkflow)
            || $allowedWorkflow === ''
            || ($claims['iss'] ?? null) !== config('deployment_approval.oidc_issuer')
            || !in_array(config('deployment_approval.oidc_audience'), $audiences, true)
            || !is_numeric($claims['exp'] ?? null)
            || !is_numeric($claims['nbf'] ?? null)
            || !is_numeric($claims['iat'] ?? null)
            || (int) ($claims['exp'] ?? 0) <= $now
            || (int) ($claims['nbf'] ?? 0) > $now + 30
            || (int) ($claims['iat'] ?? 0) > $now + 30
            || (int) ($claims['iat'] ?? 0) < $now - 600
            || (int) ($claims['run_id'] ?? 0) <= 0
            || (int) ($claims['run_attempt'] ?? 0) <= 0
            || ($claims['repository'] ?? null) !== config('deployment_approval.repository')
            || ($claims['repository_owner'] ?? null) !== 'jawadrao5555-alt'
            || ($claims['ref'] ?? null) !== 'refs/heads/main'
            || ($claims['workflow_ref'] ?? null) !== $allowedWorkflow
            || !preg_match('/^[0-9a-f]{40}$/i', (string) ($claims['workflow_sha'] ?? ''))
            || !in_array(($claims['event_name'] ?? null), $expectedWorkflow === 'approval-dispatch.yml'
                ? ['schedule', 'workflow_dispatch'] : ['workflow_dispatch'], true)
        ) {
            abort(403, 'OIDC workflow identity is not allowed.');
        }

        return $claims;
    }

    private function signingKey(string $kid): array
    {
        $find = fn (array $set): ?array => collect($set['keys'] ?? [])->first(
            fn ($key) => is_array($key) && ($key['kid'] ?? null) === $kid
        );

        $keys = $this->jwkSet();
        $key = $find($keys);

        if (!$key) {
            Cache::forget('github-actions-oidc-jwks');
            $key = $find($this->jwkSet());
        }

        if (!$key || ($key['kty'] ?? null) !== 'RSA' || ($key['use'] ?? 'sig') !== 'sig') {
            abort(401, 'Unknown OIDC signing key.');
        }

        return $key;
    }

    private function jwkSet(): array
    {
        return Cache::remember('github-actions-oidc-jwks', 300, function (): array {
            $response = Http::acceptJson()
                ->timeout(5)
                ->get('https://token.actions.githubusercontent.com/.well-known/jwks');

            if (!$response->successful() || !is_array($response->json('keys'))) {
                throw ValidationException::withMessages(['oidc' => 'GitHub OIDC signing keys are unavailable.']);
            }

            return $response->json();
        });
    }

    private function decodeJsonSegment(string $value): array
    {
        $decoded = json_decode($this->decodeSegment($value), true);

        if (!is_array($decoded)) {
            abort(401, 'Malformed OIDC token.');
        }

        return $decoded;
    }

    private function decodeSegment(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        if ($decoded === false) {
            abort(401, 'Malformed OIDC token.');
        }

        return $decoded;
    }
}