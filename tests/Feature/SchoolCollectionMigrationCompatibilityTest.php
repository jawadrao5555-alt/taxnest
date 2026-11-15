<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Upgrade proof uses synthetic pre-upgrade data, not rewritten fixtures. */
class SchoolCollectionMigrationCompatibilityTest extends TestCase
{
    public function test_additive_ledger_upgrade_preserves_receipts_and_permanent_closings_byte_for_byte(): void
    {
        Schema::dropAllTables();
        foreach (['2026_11_14_120000_create_school_fee_receipts_table.php',
            '2026_11_14_120100_create_school_fee_day_closes_table.php',
            '2026_11_15_010000_extend_school_fee_collection_details.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        foreach ([[17, null], [17, 31], [18, 42]] as $index => [$company, $campus]) {
            DB::table('school_fee_receipts')->insert([
                'company_id' => $company, 'branch_id' => $campus, 'collected_by' => 300 + $index,
                'request_key' => '00000000-0000-4000-8000-'.str_pad((string) $index, 12, '0', STR_PAD_LEFT),
                'student_reference' => 'SYN-OLD-'.$index, 'fee_head' => 'tuition',
                'amount_paisa' => 103451 + $index, 'collected_at' => '2026-11-14 10:11:12',
                'fee_period' => '2026-10', 'fee_head_label' => 'Original fee '.$index,
                'campus_name' => 'Original campus '.$index, 'cashier_name' => 'Original cashier '.$index,
                'created_at' => '2026-11-14 10:11:12', 'updated_at' => '2026-11-14 10:11:12',
            ]);
            DB::table('school_fee_day_closes')->insert([
                'company_id' => $company, 'branch_key' => $campus ?? 0, 'report_date' => '2026-11-14',
                'cash_amount_paisa' => 103451 + $index, 'receipt_count' => 1,
                'counted_cash_paisa' => 103400, 'variance_paisa' => -51 - $index,
                'closed_by' => 300 + $index, 'closed_at' => '2026-11-14 18:12:13',
                'created_at' => '2026-11-14 18:12:13', 'updated_at' => '2026-11-14 18:12:13',
            ]);
        }
        $receipts = DB::table('school_fee_receipts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $closings = DB::table('school_fee_day_closes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $migration = require database_path('migrations/2026_11_15_030000_extend_school_collection_ledger.php');
        $migration->up();
        $this->assertSame(0, DB::table('school_fee_accounts')->count(), 'Do not reinterpret historical receipts as new fee accounts.');
        foreach ($receipts as $before) {
            $after = (array) DB::table('school_fee_receipts')->find($before['id']);
            $this->assertSame($before, array_intersect_key($after, $before));
            $this->assertSame('cash', $after['payment_method']);
            foreach (['account_id', 'requested_account_id', 'fee_lines', 'payable_paisa', 'paid_paisa', 'balance_paisa'] as $field) {
                $this->assertNull($after[$field], $field.' must not invent historical balances.');
            }
        }
        foreach ($closings as $before) {
            $after = (array) DB::table('school_fee_day_closes')->find($before['id']);
            $this->assertSame($before, array_intersect_key($after, $before));
            $this->assertNull($after['variance_reason']);
            $this->assertNull($after['handover_to']);
            $this->assertNull($after['handover_note']);
        }
        $migration->down();
        $this->assertSame($receipts, DB::table('school_fee_receipts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($closings, DB::table('school_fee_day_closes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    }
}
