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
        DB::table('hospital_services')->insert(['company_id'=>17,'code'=>'synthetic-service','label'=>'Synthetic service',
            'service_head'=>'lab','rate_paisa'=>12000,'active'=>true,'created_at'=>'2026-11-16 10:00:00','updated_at'=>'2026-11-16 10:00:00']);
        $newInvoice=$before['hospital_invoices'];unset($newInvoice['id']);
        $invoiceId=DB::table('hospital_invoices')->insertGetId(array_replace($newInvoice,[
            'created_at'=>'2026-11-16 10:00:00','updated_at'=>'2026-11-16 10:00:00',
            'request_key'=>'synthetic-new-invoice','patient_reference'=>'SYN-NEW','patient_name'=>'Synthetic patient','total_paisa'=>12000,
            'service_lines'=>json_encode([['service_head'=>'consultation','label'=>'Synthetic consultation','amount_paisa'=>9000],['service_head'=>'lab','label'=>'Synthetic lab','amount_paisa'=>3000]]),
        ]));
        $newPayment=$before['hospital_payments'];unset($newPayment['id']);
        DB::table('hospital_payments')->insert(array_replace($newPayment,[
            'invoice_id'=>$invoiceId,'request_key'=>'synthetic-new-payment','amount_paisa'=>6000,'balance_paisa'=>6000,
            'collected_at'=>'2026-11-16 10:00:00','created_at'=>'2026-11-16 10:00:00','updated_at'=>'2026-11-16 10:00:00',
            'payment_method'=>'bank','payment_reference'=>'SYN-BANK-REFERENCE',
        ]));
        $newClose=$before['hospital_day_closes'];unset($newClose['id']);
        DB::table('hospital_day_closes')->insert(array_replace($newClose,['report_date'=>'2026-11-16','bank_paisa'=>6000,'closed_at'=>'2026-11-16 18:00:00','created_at'=>'2026-11-16 18:00:00','updated_at'=>'2026-11-16 18:00:00']));
        $saved=[];
        foreach(['hospital_services','hospital_invoices','hospital_payments','hospital_day_closes'] as $table) $saved[$table]=DB::table($table)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
        for($attempt=0;$attempt<2;$attempt++) {
            $migration->down();
            foreach($saved as$table=>$rows)$this->assertSame($rows,DB::table($table)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all());
            $migration->up();$migration->up();
            foreach($saved as$table=>$rows)$this->assertSame($rows,DB::table($table)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all());
        }
        foreach($before as$table=>$row)$this->assertSame($row,array_intersect_key((array)DB::table($table)->where('id',$row['id'])->first(),$row));
    }
}
