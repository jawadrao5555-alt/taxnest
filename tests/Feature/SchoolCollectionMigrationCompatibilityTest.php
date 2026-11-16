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
        $lines = json_encode([['fee_head'=>'tuition','label'=>'Synthetic tuition','amount_paisa'=>9000], ['fee_head'=>'exam','label'=>'Synthetic exam','amount_paisa'=>3000]]);
        $account = DB::table('school_fee_accounts')->insertGetId([
            'company_id'=>17,'branch_id'=>31,'student_reference'=>'SYN-NEW','student_name'=>'Synthetic student',
            'fee_period'=>'2026-11','fee_lines'=>$lines,'payable_paisa'=>12000,'paid_paisa'=>6000,
            'created_at'=>'2026-11-16 10:00:00','updated_at'=>'2026-11-16 10:00:00',
        ]);
        $newReceipt = $receipts[1]; unset($newReceipt['id']);
        DB::table('school_fee_receipts')->insert(array_replace($newReceipt, [
            'request_key'=>'00000000-0000-4000-8000-000000000100','account_id'=>$account,'requested_account_id'=>$account,
            'student_reference'=>'SYN-NEW','student_name'=>'Synthetic student','fee_head'=>'multiple','fee_period'=>'2026-11',
            'collected_at'=>'2026-11-16 10:00:00','created_at'=>'2026-11-16 10:00:00','updated_at'=>'2026-11-16 10:00:00',
            'payment_method'=>'bank','amount_paisa'=>6000,'fee_lines'=>$lines,'payable_paisa'=>12000,'paid_paisa'=>6000,'balance_paisa'=>6000,
        ]));
        $newClosing = $closings[1]; unset($newClosing['id']);
        DB::table('school_fee_day_closes')->insert(array_replace($newClosing, [
            'closed_at'=>'2026-11-16 18:00:00','created_at'=>'2026-11-16 18:00:00','updated_at'=>'2026-11-16 18:00:00',
            'report_date'=>'2026-11-16','cash_amount_paisa'=>0,'counted_cash_paisa'=>50,'variance_paisa'=>50,
            'variance_reason'=>'Synthetic overage','handover_to'=>'Synthetic supervisor','handover_note'=>'Synthetic sealed envelope',
        ]));
        $saved = [];
        foreach (['school_fee_accounts','school_fee_receipts','school_fee_day_closes'] as $table) {
            $saved[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r)=>(array)$r)->all();
        }
        for ($attempt=0; $attempt<2; $attempt++) {
            $migration->down();
            foreach ($saved as $table=>$rows) $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn ($r)=>(array)$r)->all());
            $migration->up(); $migration->up();
            foreach ($saved as $table=>$rows) $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn ($r)=>(array)$r)->all());
        }
        // Keep the original pre-upgrade history equality, including other tenants/campuses.
        foreach ($receipts as $before) $this->assertSame($before, array_intersect_key((array)DB::table('school_fee_receipts')->find($before['id']), $before));
        foreach ($closings as $before) $this->assertSame($before, array_intersect_key((array)DB::table('school_fee_day_closes')->find($before['id']), $before));
    }
}
