<?php

namespace Tests\Unit;

use App\Support\DevStagingGuard;
use Tests\TestCase;

/**
 * Fail-closed identity checks for destructive local/dev tooling.
 * PHPUnit uses sqlite :memory:, so problems() must always be non-empty here.
 */
class DevStagingGuardTest extends TestCase
{
    public function test_allowed_database_names_include_cloud_and_replit_local(): void
    {
        $this->assertSame('taxnest_staging', DevStagingGuard::DB_NAME);
        $this->assertContains('taxnest_staging', DevStagingGuard::DB_NAMES);
        $this->assertContains('taxnest_dev', DevStagingGuard::DB_NAMES);
        $this->assertContains('127.0.0.1', DevStagingGuard::HOSTS);
        $this->assertContains('localhost', DevStagingGuard::HOSTS);
    }

    public function test_phpunit_sqlite_connection_is_rejected(): void
    {
        $problems = DevStagingGuard::problems();
        $this->assertNotEmpty($problems, 'sqlite PHPUnit DB must not pass DevStagingGuard');
        $this->assertTrue(
            collect($problems)->contains(fn (string $p) => str_contains($p, 'driver')),
            'expected a driver mismatch problem, got: '.implode('; ', $problems)
        );
    }

    public function test_assert_local_staging_refuses_without_opt_in_flag(): void
    {
        // VIDEO_PIPELINE_ALLOW is unset in PHPUnit — assert must throw before DB checks matter.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('VIDEO_PIPELINE_ALLOW=1');
        DevStagingGuard::assertLocalStaging('unit-test');
    }
}
