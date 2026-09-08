<?php

namespace Tests\Feature\LiveOps;

use App\Services\LiveOps\LiveOpsRemediationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Runner API execute(): an UNEXPECTED exception must never echo its message
 * (SQL text, file paths, hostnames) to the caller — only a generic error plus
 * a correlation id, with the real exception logged server-side. Intentional
 * domain guards (InvalidArgumentException → 422) keep their messages.
 */
class LiveOpsRunnerErrorLeakTest extends LiveOpsTestCase
{
    private const SECRET_DETAIL = 'SQLSTATE[HY000] boom at /var/www/secret/path.php (db-host-internal)';

    private function token(): string
    {
        $token = str_repeat('c', 40);
        Config::set('live_ops.runner_token', $token);
        return $token;
    }

    private function bindThrowing(\Throwable $e): void
    {
        $mock = $this->getMockBuilder(LiveOpsRemediationService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute'])
            ->getMock();
        $mock->method('execute')->willThrowException($e);
        $this->app->instance(LiveOpsRemediationService::class, $mock);
    }

    public function test_unexpected_exception_is_not_leaked_and_is_logged_with_correlation_id(): void
    {
        $token = $this->token();
        $this->bindThrowing(new \RuntimeException(self::SECRET_DETAIL));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'remediation execution failed')
                    && ($context['action_id'] ?? null) === 'act-leak-1'
                    && array_key_exists('company_id', $context)
                    && !empty($context['correlation_id'])
                    && ($context['exception'] ?? null) instanceof \RuntimeException;
            });

        $response = $this->postJson('/api/live-ops/v1/remediate/act-leak-1/execute', [
            'executor' => 'github-actions',
        ], ['X-Live-Ops-Token' => $token]);

        $response->assertStatus(500)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Remediation execution failed')
            ->assertJsonStructure(['ok', 'error', 'correlation_id']);

        $body = $response->getContent();
        $this->assertStringNotContainsString(self::SECRET_DETAIL, $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('/var/www', $body);
        $this->assertStringNotContainsString('db-host-internal', $body);
        $this->assertNotEmpty($response->json('correlation_id'));
    }

    public function test_domain_guard_messages_are_still_surfaced_as_422(): void
    {
        $token = $this->token();
        $this->bindThrowing(new \InvalidArgumentException('High-risk action blocked at execution'));

        $this->postJson('/api/live-ops/v1/remediate/act-guard-1/execute', [], [
            'X-Live-Ops-Token' => $token,
        ])->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'High-risk action blocked at execution');
    }

    public function test_unknown_action_id_is_a_generic_404(): void
    {
        $token = $this->token();

        $response = $this->postJson('/api/live-ops/v1/remediate/does-not-exist/execute', [], [
            'X-Live-Ops-Token' => $token,
        ]);

        $response->assertStatus(404)->assertJsonPath('error', 'Remediation request not found');
        $this->assertStringNotContainsString('No query results', $response->getContent());
        $this->assertStringNotContainsString('LiveOpsRemediationRequest', $response->getContent());
    }
}
