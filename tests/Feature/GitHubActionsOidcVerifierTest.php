<?php

namespace Tests\Feature;

use App\Services\GitHubActionsOidcVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Jose\Component\Core\Util\RSAKey;
use Jose\Component\KeyManagement\JWKFactory;
use Tests\TestCase;

class GitHubActionsOidcVerifierTest extends TestCase
{
    private string $token;
    private \Jose\Component\Core\JWK $signingKey;

    protected function setUp(): void
    {
        parent::setUp();

        $key = JWKFactory::createRSAKey(2048, [
            'kid' => 'deterministic-test-kid',
            'use' => 'sig',
            'alg' => 'RS256',
        ]);
        $this->signingKey = $key;
        Http::fake([
            'https://token.actions.githubusercontent.com/.well-known/jwks' => Http::response([
                'keys' => [$key->toPublic()->all()],
            ]),
        ]);

        $claims = [
            'iss' => config('deployment_approval.oidc_issuer'),
            'aud' => [config('deployment_approval.oidc_audience')],
            'exp' => time() + 300,
            'nbf' => time() - 5,
            'iat' => time(),
            'run_id' => 12345,
            'run_attempt' => 1,
            'event_name' => 'workflow_dispatch',
            'repository' => config('deployment_approval.repository'),
            'repository_owner' => 'jawadrao5555-alt',
            'ref' => 'refs/heads/main',
            'workflow_ref' => config('deployment_approval.allowed_workflow_refs')['owner-merge-and-deploy.yml'],
            'workflow_sha' => str_repeat('a', 40),
        ];
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'deterministic-test-kid'];
        $encodedHeader = $this->base64url(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = $this->base64url(json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = $encodedHeader.'.'.$encodedClaims;
        openssl_sign($signingInput, $signature, RSAKey::createFromJWK($key)->toPEM(), OPENSSL_ALGO_SHA256);
        $this->token = $signingInput.'.'.$this->base64url($signature);
    }

    public function test_exact_allowlisted_workflow_and_github_claims_are_accepted(): void
    {
        $claims = app(GitHubActionsOidcVerifier::class)->verify(
            Request::create('/', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]),
            'owner-merge-and-deploy.yml'
        );

        $this->assertSame(config('deployment_approval.repository'), $claims['repository']);
        Http::assertSentCount(1);
    }

    /**
     * @dataProvider rejectedClaimProvider
     */
    public function test_invalid_signature_or_identity_claim_is_rejected(string $claim, mixed $value): void
    {
        $token = $claim === 'signature'
            ? implode('.', [explode('.', $this->token)[0], explode('.', $this->token)[1], 'x'.substr(explode('.', $this->token)[2], 1)])
            : $this->tokenWithClaim($claim, $value);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(GitHubActionsOidcVerifier::class)->verify(
            Request::create('/', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]),
            'owner-merge-and-deploy.yml'
        );
    }

    public static function rejectedClaimProvider(): array
    {
        return [
            'invalid signature' => ['signature', null],
            'wrong audience' => ['aud', ['not-owner-approval-relay']],
            'wrong repository' => ['repository', 'someone-else/repo'],
            'wrong ref' => ['ref', 'refs/heads/release'],
            'wrong workflow' => ['workflow_ref', 'jawadrao5555-alt/taxnest/.github/workflows/other.yml@refs/heads/main'],
            'stale iat' => ['iat', time() - 601],
            'missing run id' => ['run_id', 0],
            'missing run attempt' => ['run_attempt', 0],
            'wrong event' => ['event_name', 'push'],
        ];
    }

    private function tokenWithClaim(string $claim, mixed $value): string
    {
        $parts = explode('.', $this->token);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
        $claims[$claim] = $value;
        $payload = $this->base64url(json_encode($claims, JSON_THROW_ON_ERROR));
        $input = $parts[0].'.'.$payload;
        // The verifier must reject identity changes even when the signature is valid.
        openssl_sign($input, $signature, RSAKey::createFromJWK($this->signingKey)->toPEM(), OPENSSL_ALGO_SHA256);
        return $input.'.'.$this->base64url($signature);
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}