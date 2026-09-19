<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FbrPosCallbackDiagnosticsMigrationTest extends TestCase
{
    public function test_composite_indexes_have_explicit_mariadb_safe_names(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_12_02_000000_create_fbr_pos_callback_diagnostics.php'
        );

        $this->assertIsString($migration);
        $this->assertStringContainsString(
            "\$table->index(['company_id', 'callback_received_at'], 'fbr_cbdiag_company_received_idx');",
            $migration
        );
        $this->assertStringContainsString(
            "\$table->index(['transaction_id', 'callback_received_at'], 'fbr_cbdiag_tx_received_idx');",
            $migration
        );

        preg_match_all('/->index\([^;]+,\s*[\'\"]([^\'\"]+)[\'\"]\s*\);/', $migration, $matches);
        $this->assertCount(2, $matches[1]);
        foreach ($matches[1] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }

        $this->assertDoesNotMatchRegularExpression(
            '/->index\(\s*\[[^;]+\]\s*\);/',
            $migration,
            'Composite indexes must not fall back to Laravel auto-generated names.'
        );
    }
}
