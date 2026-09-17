<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class RepositoryArtifactGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_retired_sensitive_diagnostics_and_data_exports_are_absent(): void
    {
        $retiredArtifacts = [
            'cookies.txt',
            'fbr_retry_log.txt',
            'fbr_retry.php',
            'fbr_single_try.php',
            'fbr_retry_loop.sh',
            'generate-annex-invoices.php',
            'generate-full-demo-invoice.php',
            'generate-nisar-invoices.php',
            'generate-sample-modern-di.php',
            'routes-update.zip',
            'database/production_data_export.sql',
            'caller-app/TaxNest-PRA-Agent-Windows.zip',
            'public/downloads/TaxNest-PRA-Agent-Windows.zip',
        ];

        foreach ($retiredArtifacts as $path) {
            $this->assertFileDoesNotExist($this->root . '/' . $path, $path . ' must not return to the working tree.');
        }

        // Applying a file deletion leaves an empty directory in a live
        // workspace, but Git will not retain it. Any future sync bundle file
        // is still a regression and is caught here as well as by the guard.
        $syncFiles = glob($this->root . '/database/deploy/*-production-sync/*') ?: [];
        $this->assertSame([], $syncFiles, 'Production data synchronization bundles must contain no files.');
    }

    public function test_repository_guard_rejects_tracked_sensitive_artifacts(): void
    {
        $guard = $this->root . '/scripts/verify-repository-artifacts.sh';
        $this->assertFileExists($guard);
        $this->assertTrue(is_executable($guard), 'The artifact guard must be directly runnable in CI.');

        exec('cd ' . escapeshellarg($this->root) . ' && ' . escapeshellarg($guard) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('verification passed', implode("\n", $output));
    }
}