<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guard against host-shell env leaks (e.g. an exported CACHE_STORE=file
     * beating phpunit.xml overrides). A leaked driver silently persists
     * rate-limiter/cache/session/queue state between runs and causes flaky
     * tests (429s etc.), so fail loudly and immediately instead.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        // Some app code arms a PHP execution timer for long web work
        // (@set_time_limit(300) in the ZIP/audit-pack builders). Under PHPUnit
        // that timer keeps running for the REST of the process, so every test
        // after the one that touched such a builder dies with "Maximum
        // execution time of 300 seconds exceeded" once the suite passes that
        // mark. Disarm it per test — the runner, not the app, owns time here.
        @set_time_limit(0);

        // Schema-capability memos ("does users.product_type exist?") are static
        // and would otherwise survive into the next test class — and some
        // classes here drop every table and hand-build a minimal schema. One
        // stale answer then either skips per-product scoping everywhere or
        // writes a column that class never created.
        \App\Support\IdentityScope::flushSchemaCache();
        \App\Services\CompanyGroupService::flushSchemaCache();
        \App\Http\Controllers\Auth\PasswordResetLinkController::flushSchemaCache();
        \App\Support\AgentApiKey::flushSchemaCache();
        // PosFeatureService caches plan/category answers by company id, while
        // feature tests routinely drop and rebuild minimal schemas (and SQLite
        // reuses company id 1). Never let an earlier test's entitlement answer
        // leak into the next isolated fixture.
        \App\Services\PosFeatureService::flushGateCaches();
        // Admin DI review reads the full ledger schema; later tests build
        // legacy schemas without it. Do not reuse that per-process answer.
        \App\Services\AiPageCreditService::forgetLedgerProbe();
        // Legacy fixtures may omit submitted_at; do not carry that answer into
        // a new schema and miscount paid invoices by their old draft date.
        \App\Services\PlanLimitService::forgetSchemaProbe();
        // Campaign pricing is memoized for one request. A long PHPUnit process
        // must not carry another test's active sale into a fresh database.
        \App\Models\SaleCampaign::clearActiveCache();

        $this->assertTestEnvironmentIsIsolated();
    }

    /** Release unreachable request/render graphs at the test isolation boundary. */
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // Dompdf frame/canvas graphs and Laravel request mocks contain cycles.
            // PHP's adaptive automatic threshold can defer reclaiming these across
            // thousands of tests. Keep automatic GC enabled, but also collect after
            // Laravel has released the application and Mockery has verified itself.
            // No test, PDF assertion, per-test state or memory limit is removed.
            $before = memory_get_usage();
            $cycles = gc_collect_cycles();
            $after = memory_get_usage();
            // Reclaim free Zend allocator pages too: collecting cycles alone
            // can leave their storage in allocator caches until process exit.
            // This touches only unused memory, not live application/test state.
            $allocatorFreed = function_exists('gc_mem_caches') ? gc_mem_caches() : 0;
            // Bounded, content-free evidence for long aggregate runs. At most one
            // line per class when retained pressure or reclaimed cycles are large.
            static $lastProfiledClass = null;
            if (($before >= 512 * 1024 * 1024 || $before - $after >= 32 * 1024 * 1024)
                && $lastProfiledClass !== static::class) {
                $lastProfiledClass = static::class;
                fwrite(STDERR, sprintf("PHPUnit cycle cleanup: class=%s before=%d after=%d peak=%d reclaimed=%d collected=%d allocator_freed=%d\n", static::class, $before, $after, memory_get_peak_usage(), $before - $after, $cycles, $allocatorFreed));
            }
        }
    }

    private function assertTestEnvironmentIsIsolated(): void
    {
        $problems = [];

        $expected = [
            'app.env' => 'testing',
            'cache.default' => 'array',
            'database.default' => 'sqlite',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
        ];

        foreach ($expected as $key => $value) {
            $actual = config($key);
            if ($actual !== $value) {
                $problems[] = sprintf('%s is "%s", expected "%s"', $key, $actual, $value);
            }
        }

        $db = config('database.connections.sqlite.database');
        if ($db !== ':memory:') {
            $problems[] = sprintf('database.connections.sqlite.database is "%s", expected ":memory:"', $db);
        }

        if ($problems !== []) {
            self::fail(
                "Test environment is not isolated — a shell-exported env var is likely leaking past phpunit.xml overrides.\n"
                . "Fix: bake the correct value into the test command env (or add force=\"true\" in phpunit.xml).\n"
                . '- ' . implode("\n- ", $problems)
            );
        }
    }
}
