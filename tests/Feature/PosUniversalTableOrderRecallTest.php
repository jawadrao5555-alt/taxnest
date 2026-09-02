<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PRA sale-screen table recall regression coverage.
 *
 * Table-status and by-table are separate MySQL result sets: one can carry an
 * integer order id while the other carries the same id as a string.  Matching
 * must normalize both values, and a failed match must not silently recall the
 * first (possibly different) order returned for a multi-order table.
 */
class PosUniversalTableOrderRecallTest extends TestCase
{
    public function test_occupied_table_open_edit_normalizes_mysql_order_ids_without_sibling_fallback(): void
    {
        $blade = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $this->assertNotFalse($blade);

        $boardViewEdit = $this->methodBody($blade, 'async boardViewEdit()');
        $directOpen = $this->methodBody($blade, 'async directOpenTable(t)');

        foreach ([$boardViewEdit, $directOpen] as $method) {
            $this->assertStringContainsString(
                'list.find(o => Number(o.id) === Number(t.order.id))',
                $method,
                'The stored tile order must match whether MySQL returns string or integer ids.'
            );
            $this->assertStringNotContainsString(
                '|| list[0]',
                $method,
                'A failed match must not load another open order from the same table.'
            );
        }
    }

    public function test_boot_prefers_explicit_order_but_keeps_the_occupied_table_rescue_fallback(): void
    {
        $blade = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $this->assertNotFalse($blade);

        $autoRecall = $this->methodBody($blade, 'async autoRecallFromUrl()');
        $this->assertStringContainsString('if (!rid) return this.autoOpenPreselectedTable();', $autoRecall);
        $this->assertStringContainsString(
            'this.heldOrders.find(o => Number(o.id) === Number(rid))',
            $autoRecall
        );
        $this->assertStringContainsString('if (!ord) return this.autoOpenPreselectedTable();', $autoRecall);

        // Keep tenant-selected click behavior intact: OFF continues to open the
        // action menu; ON alone uses the explicit direct-edit path.
        $selectTable = $this->methodBody($blade, 'async selectTable(table, opts)');
        $this->assertStringContainsString(
            'if (this.tableClickDirectOpen) { await this.directOpenTable(table); } else { this.openBoardMenu(table); }',
            $selectTable
        );
    }

    private function methodBody(string $blade, string $signature): string
    {
        $start = strpos($blade, $signature);
        $this->assertNotFalse($start, "{$signature} not found");

        $next = strpos($blade, "\n        },", $start);
        $this->assertNotFalse($next, "end of {$signature} not found");

        return substr($blade, $start, $next - $start);
    }
}