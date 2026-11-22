<?php
namespace Tests\Feature\Reporting;

use App\Models\Company;
use App\Models\CompanyUsageStat;
use App\Services\Reporting\CompanyReadReports;
use App\Services\Reporting\DiagnosticSourceEvidence;
use App\Services\Reporting\LocalCollectionReports;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocalCollectionReportsTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp(); Schema::dropAllTables();
        config(['app.timezone'=>'UTC','reporting_connector.timezone'=>'UTC']);
        Schema::create('companies',function(Blueprint $t){$t->id();$t->string('name');$t->string('product_type');$t->string('pos_type')->nullable();$t->string('business_category')->nullable();$t->softDeletes();});
        Schema::create('branches',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');});
        foreach(['school_fee_accounts','hospital_invoices'] as $table) Schema::create($table,function(Blueprint $t)use($table){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->unsignedBigInteger($table==='school_fee_accounts'?'payable_paisa':'total_paisa');$t->timestamps();});
        foreach(['school_fee_receipts','hospital_payments'] as $table) Schema::create($table,function(Blueprint $t)use($table){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_id')->nullable();$t->unsignedBigInteger($table==='school_fee_receipts'?'account_id':'invoice_id')->nullable();$t->unsignedBigInteger('amount_paisa');$t->string('payment_method');$t->timestamp('collected_at');});
        foreach(['school_fee_day_closes','hospital_day_closes'] as $table) Schema::create($table,function(Blueprint $t)use($table){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('branch_key');$t->date('report_date');$t->integer('receipt_count');$t->unsignedBigInteger($table==='school_fee_day_closes'?'cash_amount_paisa':'cash_paisa');$t->unsignedBigInteger('counted_cash_paisa')->nullable();$t->bigInteger('variance_paisa')->nullable();$t->timestamp('closed_at');});
        DB::table('companies')->insert([['id'=>1,'name'=>'Synthetic School','product_type'=>'fbrpos','pos_type'=>'education'],['id'=>2,'name'=>'Synthetic Hospital','product_type'=>'fbrpos','pos_type'=>'clinic'],['id'=>3,'name'=>'Other Hospital','product_type'=>'fbrpos','pos_type'=>'clinic']]);
        DB::table('branches')->insert([['id'=>11,'company_id'=>2],['id'=>12,'company_id'=>2],['id'=>31,'company_id'=>3]]);
    }
    private function report(int $id,array $filters=[]): array { return app(LocalCollectionReports::class)->read(Company::findOrFail($id),$filters); }
    public function test_company_usage_csv_matches_the_filtered_current_and_comparison_snapshots(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2024-02-29 12:00:00', 'UTC'));
        foreach ([[2, 11, 1200, '2024-02-29'], [2, 11, 450, '2023-02-28'], [2, 11, 7300, '2024-03-01'], [2, 12, 9200, '2024-02-29'], [3, 31, 3400, '2024-02-29']] as [$company, $branch, $amount, $date]) {
            DB::table('hospital_invoices')->insert(['company_id' => $company, 'branch_id' => $branch, 'total_paisa' => $amount, 'created_at' => $date]);
        }
        $this->travel(1)->day();
        $request = \Illuminate\Http\Request::create('/admin/company-usage', 'GET', [
            'company_id' => 2, 'branch_id' => 11, 'period' => 'two_year', 'export' => 'local_csv', 'as_of' => '2024-02-29',
        ]);
        $response = app(\App\Http\Controllers\SaasAdmin\AdminUsageController::class)->index($request);
        $csv = \Illuminate\Testing\TestResponse::fromBaseResponse($response)->streamedContent();
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));
        $this->assertCount(3, $rows);
        $this->assertSame(['2', '11', '2024-01-01', '2024-02-29'], array_slice($rows[1], 0, 4));
        $this->assertSame(['1200', '0', '1650', ''], array_slice($rows[1], 5));
        $this->assertSame(['2', '11', '2023-01-01', '2023-02-28'], array_slice($rows[2], 0, 4));
        $this->assertSame(['450', '0', '450', ''], array_slice($rows[2], 5));
    }

    public function test_hospital_principal_receipts_asof_balances_and_closing_are_separate(): void {
        DB::table('hospital_invoices')->insert([['id'=>1,'company_id'=>2,'branch_id'=>11,'total_paisa'=>100000,'created_at'=>'2026-11-01 10:00:00'],['id'=>2,'company_id'=>2,'branch_id'=>12,'total_paisa'=>90000,'created_at'=>'2026-11-01 10:00:00']]);
        DB::table('hospital_payments')->insert([['company_id'=>2,'branch_id'=>11,'invoice_id'=>1,'amount_paisa'=>40000,'payment_method'=>'cash','collected_at'=>'2026-11-01 11:00:00'],['company_id'=>2,'branch_id'=>11,'invoice_id'=>1,'amount_paisa'=>20000,'payment_method'=>'bank','collected_at'=>'2026-11-02 11:00:00'],['company_id'=>2,'branch_id'=>11,'invoice_id'=>1,'amount_paisa'=>40000,'payment_method'=>'card','collected_at'=>'2026-11-03 11:00:00'],['company_id'=>3,'branch_id'=>31,'invoice_id'=>1,'amount_paisa'=>12345,'payment_method'=>'cash','collected_at'=>'2026-11-02 11:00:00']]);
        DB::table('hospital_day_closes')->insert(['company_id'=>2,'branch_key'=>11,'report_date'=>'2026-11-02','receipt_count'=>1,'cash_paisa'=>0,'counted_cash_paisa'=>null,'variance_paisa'=>null,'closed_at'=>'2026-11-02 23:00:00']);
        $before=DB::table('hospital_day_closes')->first();
        $r=$this->report(2,['branch_id'=>11,'date_from'=>'2026-11-02','date_to'=>'2026-11-02']);
        $this->assertSame(0,$r['billing']['principal_paisa']);$this->assertSame(20000,$r['payments']['collected_paisa']);$this->assertSame(40000,$r['balances']['outstanding_paisa']);
        $this->assertSame(0,$r['closings']['cash_paisa']);$this->assertNull($r['closings']['counted_cash_paisa']);$this->assertFalse($r['coverage']['complete']);
        $this->assertEquals($before,DB::table('hospital_day_closes')->first());
        $all=$this->report(2,['branch_id'=>11]);$this->assertSame(100000,$all['billing']['principal_paisa']);$this->assertSame(100000,$all['payments']['collected_paisa']);$this->assertSame(3,$all['payments']['receipt_count']);$this->assertSame(0,$all['balances']['outstanding_paisa']);
    }
    public function test_school_legacy_cash_is_not_double_counted_with_multi_payment_accounts(): void {
        DB::table('school_fee_accounts')->insert(['id'=>1,'company_id'=>1,'branch_id'=>null,'payable_paisa'=>100000,'created_at'=>'2026-11-01 10:00:00']);
        foreach([[1,40000,'cash'],[1,60000,'bank'],[null,25000,'cash']] as [$id,$amount,$method]) DB::table('school_fee_receipts')->insert(['company_id'=>1,'branch_id'=>null,'account_id'=>$id,'amount_paisa'=>$amount,'payment_method'=>$method,'collected_at'=>'2026-11-01 11:00:00']);
        $r=$this->report(1,['branch_id'=>0]);$this->assertSame(125000,$r['billing']['principal_paisa']);$this->assertSame(2,$r['billing']['documents']);$this->assertSame(125000,$r['payments']['collected_paisa']);$this->assertSame(3,$r['payments']['receipt_count']);$this->assertSame(0,$r['balances']['outstanding_paisa']);$this->assertNull($r['closings']['cash_paisa']);
        $usage=CompanyUsageStat::snapshotsForCompanies(collect([Company::find(1)]))->first();$this->assertNull($usage->total_sales_amount);$this->assertSame(125000,$usage->local_collections['payments']['collected_paisa']);
    }
    public function test_missing_is_unavailable_but_real_zero_is_zero_and_bad_scope_rejected(): void {
        $r=$this->report(2);$this->assertSame(0,$r['payments']['collected_paisa']);$this->assertSame('NO_RECORDED_CLOSE',$r['closings']['status']);
        try{$this->report(2,['branch_id'=>31]);$this->fail();}catch(ValidationException $e){$this->assertArrayHasKey('branch_id',$e->errors());}
        try{$this->report(2,['date_basis'=>'business']);$this->fail();}catch(ValidationException $e){$this->assertArrayHasKey('date_basis',$e->errors());}
        Schema::drop('hospital_payments');$r=$this->report(2);$this->assertSame('UNAVAILABLE',$r['coverage']['status']);$this->assertNull($r['payments']);
    }
    public function test_reporting_adapter_scopes_local_summary_and_refuses_retail_mislabel(): void {
        $args=['company_name'=>'Synthetic Hospital','company_id'=>2,'date_from'=>'2026-11-01'];
        $r=app(CompanyReadReports::class)->summary($args,[2]);$this->assertSame('LOCAL_ONLY_FISCAL_BLOCKED',$r['authority']);
        try{app(CompanyReadReports::class)->summary($args,[1]);$this->fail();}catch(ValidationException $e){$this->assertArrayHasKey('company_id',$e->errors());}
        try{app(CompanyReadReports::class)->context($args,[2]);$this->fail();}catch(ValidationException $e){$this->assertArrayHasKey('report',$e->errors());}
    }
    public function test_diagnostic_local_source_returns_ids_not_patient_details_and_branch_closing_scope(): void {
        DB::table('hospital_day_closes')->insert(['company_id'=>2,'branch_key'=>11,'report_date'=>'2026-11-02','receipt_count'=>1,'cash_paisa'=>0,'closed_at'=>'2026-11-02 23:00:00']);
        $args=['company_name'=>'Synthetic Hospital','company_id'=>2,'branch_id'=>11,'date_from'=>'2026-11-02','source'=>'fbrpos.hospital.closings'];
        $r=app(DiagnosticSourceEvidence::class)->read($args,[2]);$this->assertSame('PARTIAL',$r['status']);$this->assertCount(1,$r['rows']);$this->assertSame(['id','event_at'],array_keys($r['rows'][0]));
        $r=app(DiagnosticSourceEvidence::class)->read(array_replace($args,['branch_id'=>12]),[2]);$this->assertSame('NO_RETAINED_EVENTS',$r['status']);
        try{app(DiagnosticSourceEvidence::class)->read(array_replace($args,['source'=>'fbrpos.school.receipts']),[2]);$this->fail();}catch(ValidationException $e){$this->assertArrayHasKey('source',$e->errors());}
    }

    public function test_unmatched_payment_prevents_false_zero_outstanding_and_zero_ui_is_distinct(): void {
        $zero=$this->report(2);
        $html=view('saas-admin.partials.local-collections',['local'=>$zero])->render();
        $this->assertStringContainsString('PKR 0.00',$html);$this->assertStringContainsString('Unavailable: no saved close',$html);
        DB::table('hospital_payments')->insert(['company_id'=>2,'branch_id'=>null,'invoice_id'=>999,'amount_paisa'=>12000,'payment_method'=>'cash','collected_at'=>'2026-11-01 11:00:00']);
        $report=$this->report(2);$this->assertSame(12000,$report['payments']['collected_paisa']);$this->assertNull($report['balances']['outstanding_paisa']);$this->assertSame(1,$report['balances']['unmatched_payment_receipts']);
        $html=view('saas-admin.partials.local-collections',['local'=>$report])->render();$this->assertStringContainsString('Unknown: unmatched payment records',$html);
    }
}
