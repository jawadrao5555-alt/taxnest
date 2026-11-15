<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class HospitalCollectionMigrationCompatibilityTest extends TestCase
{
    public function test_hospital_catalog_upgrade_preserves_saved_invoices_payments_and_closing(): void
    {
        Schema::dropAllTables();
        (require database_path('migrations/2026_11_15_020000_create_hospital_collection_tables.php'))->up();
        foreach ([['hospital_invoices', ['request_key'=>'old-invoice','patient_reference'=>'SYN-OLD','service_head'=>'lab','total_paisa'=>34500,'created_by'=>3,'campus_name'=>'Original campus','branch_id'=>null]],
            ['hospital_payments',['invoice_id'=>1,'request_key'=>'old-payment','amount_paisa'=>20000,'payment_method'=>'bank','balance_paisa'=>14500,'collected_by'=>3,'cashier_name'=>'Original cashier','campus_name'=>'Original campus','collected_at'=>'2026-11-14 12:00:00','branch_id'=>null]],
            ['hospital_day_closes',['branch_key'=>0,'report_date'=>'2026-11-14','cash_paisa'=>0,'card_paisa'=>0,'bank_paisa'=>20000,'counted_cash_paisa'=>0,'variance_paisa'=>0,'variance_note'=>'Original note','receipt_count'=>1,'closed_by'=>3,'cashier_name'=>'Original cashier','campus_name'=>'Original campus','closed_at'=>'2026-11-14 18:00:00']]] as [$table,$data]) {
            DB::table($table)->insert($data+['company_id'=>17,'created_at'=>'2026-11-14 12:00:00','updated_at'=>'2026-11-14 12:00:00']);
        }
        $before=[];
        foreach(['hospital_invoices','hospital_payments','hospital_day_closes'] as $table) $before[$table]=(array)DB::table($table)->first();
        $migration=require database_path('migrations/2026_11_15_040000_extend_hospital_collection_ledger.php');$migration->up();
        foreach($before as $table=>$row) $this->assertSame($row,array_intersect_key((array)DB::table($table)->first(),$row));
        $this->assertNull(DB::table('hospital_invoices')->value('service_lines'));
        $this->assertNull(DB::table('hospital_payments')->value('payment_reference'));
        $this->assertSame(0,DB::table('hospital_services')->count());
        $migration->down();
        foreach($before as $table=>$row) $this->assertSame($row,(array)DB::table($table)->first());
    }
}
