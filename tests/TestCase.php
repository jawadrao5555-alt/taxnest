<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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

        // Some app code arms a PHP execution timer for long web work
        // (@set_time_limit(300) in the ZIP/audit-pack builders). Under PHPUnit
        // that timer keeps running for the REST of the process, so every test
        // after the one that touched such a builder dies with "Maximum
        // execution time of 300 seconds exceeded" once the suite passes that
        // mark. Disarm it per test — the runner, not the app, owns time here.
        @set_time_limit(0);

        $this->assertTestEnvironmentIsIsolated();
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
