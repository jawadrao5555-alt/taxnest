<?php

namespace Tests\Feature;

use App\Exceptions\DeploymentGitHubUnavailable;
use App\Services\DeploymentGitHubReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeploymentGitHubReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_configured_read_credential_is_sent_only_to_github_reads(): void
    {
        config(['deployment_approval.github_read_token' => 'synthetic-read-token']);
        Http::fake(['*' => Http::response(['number' => 17])]);
        app(DeploymentGitHubReader::class)->get('pulls/17', 'pull_request');
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer synthetic-read-token'));
    }

    public function test_rate_limit_blocks_new_reader_requests_until_reset_then_rechecks(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 403, ['Retry-After' => '120'])->push(['number' => 17])]);
        for ($i = 0; $i < 2; $i++) {
            try {
                (new DeploymentGitHubReader)->get('pulls/17', 'pull_request');
                $this->fail('Rate limited validation must fail closed.');
            } catch (DeploymentGitHubUnavailable $e) {
                $this->assertSame(120, $e->retryAfter);
            }
        }
        Http::assertSentCount(1);
        $this->travel(121)->seconds();
        $this->assertSame(17, (new DeploymentGitHubReader)->get('pulls/17', 'pull_request')->json('number'));
        Http::assertSentCount(2);
    }

    public function test_temporary_server_failure_recovers_with_a_read_only_retry(): void
    {
        Http::fake(['*' => Http::sequence()->push(['private' => 'secret'], 502)->push(['number' => 17])]);
        $response = app(DeploymentGitHubReader::class)->get('pulls/17', 'pull_request');
        $this->assertSame(17, $response->json('number'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->method() === 'GET'
            && $r->url() === 'https://api.github.com/repos/jawadrao5555-alt/taxnest/pulls/17');
    }

    public function test_exhausted_server_failure_is_bounded_and_does_not_expose_response_body(): void
    {
        Http::fake(['*' => Http::response(['message' => 'credential-do-not-log'], 503)]);
        try {
            app(DeploymentGitHubReader::class)->get('pulls/17', 'pull_request');
            $this->fail('Unavailable GitHub cannot authorize a release.');
        } catch (DeploymentGitHubUnavailable $e) {
            $this->assertSame(503, $e->upstreamStatus);
            $this->assertSame('pull_request', $e->stage);
            $this->assertStringNotContainsString('credential-do-not-log', $e->getMessage());
            Http::assertSentCount(3);
        }
    }

    public function test_rate_limited_forbidden_response_is_not_hammered_and_retains_retry_hint(): void
    {
        Http::fake(['*' => Http::response(['message' => 'private'], 403, [
            'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 100),
        ])]);
        try {
            app(DeploymentGitHubReader::class)->get('pulls/17', 'pull_request');
            $this->fail('Rate limit cannot authorize a release.');
        } catch (DeploymentGitHubUnavailable $e) {
            $this->assertSame(403, $e->upstreamStatus);
            $this->assertGreaterThanOrEqual(90, $e->retryAfter);
            $this->assertLessThanOrEqual(100, $e->retryAfter);
            Http::assertSentCount(1);
        }
    }

    public function test_retry_after_is_bounded_without_printing_arbitrary_header_content(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '999999'])]);
        try {
            app(DeploymentGitHubReader::class)->get('pulls/17', 'pull_request');
            $this->fail('Unavailable GitHub cannot authorize a release.');
        } catch (DeploymentGitHubUnavailable $e) {
            $this->assertSame(3600, $e->retryAfter);
            Http::assertSentCount(1);
        }
    }

    public function test_connection_exception_is_bounded_and_redacted(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Authorization: private-value');
        });
        try {
            app(DeploymentGitHubReader::class)->get('pulls/17', 'pull_request');
            $this->fail('Unavailable GitHub cannot authorize a release.');
        } catch (DeploymentGitHubUnavailable $e) {
            $this->assertNull($e->upstreamStatus);
            $this->assertSame(3, $attempts);
            $this->assertStringNotContainsString('private-value', $e->getMessage());
        }
    }
}
