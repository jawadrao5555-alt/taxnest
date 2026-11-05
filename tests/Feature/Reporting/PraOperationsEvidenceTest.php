<?php

namespace Tests\Feature\Reporting;

use App\Models\Company;
use App\Services\PraAgentDiagnosticEvidence;
use App\Services\Reporting\DayCloseEvidence;
use App\Services\Reporting\PraOperationsEvidence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PraOperationsEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        config(['app.timezone' => 'Asia/Karachi']);
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(12, 0));
        Schema::create('companies', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('product_type'); $t->softDeletes(); });
        DB::table('companies')->insert([['id'=>1,'name'=>'Synthetic Cafe','product_type'=>'pos'], ['id'=>2,'name'=>'Other Cafe','product_type'=>'pos'], ['id'=>3,'name'=>'FBR Cafe','product_type'=>'fbrpos']]);
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable(); $t->date('business_date');
            $t->string('status')->default('completed'); $t->string('transaction_type')->nullable(); $t->string('invoice_mode')->nullable(); $t->string('payment_method');
            $t->decimal('total_amount',14,2); $t->unsignedBigInteger('rider_id')->nullable(); $t->decimal('rider_collection_amount',14,2)->nullable();
            $t->decimal('rider_partial_paid',14,2)->nullable(); $t->unsignedBigInteger('rider_settlement_id')->nullable(); $t->dateTime('rider_settled_at')->nullable();
            $t->string('order_type')->default('takeaway'); $t->string('delivery_status')->nullable(); $t->boolean('is_archived')->default(false);
            $t->boolean('hotel_money_from_folio')->default(false); $t->string('customer_phone')->nullable(); $t->timestamps();
        });
        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->default(0); $t->date('report_date');
            $t->integer('total_invoices')->nullable(); $t->integer('returns_count')->nullable();
            foreach (['cash_amount','card_amount','other_amount','opening_float','expected_cash','counted_cash','cash_variance'] as $c) $t->decimal($c,14,2)->nullable();
            $t->text('rider_summary')->nullable(); $t->timestamps();
        });
        Schema::create('pos_day_openings', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->default(0); $t->date('business_date'); $t->decimal('opening_cash',14,2)->nullable(); });
        Schema::create('pos_rider_settlements', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->string('panel'); $t->date('business_date')->nullable(); $t->text('allocation')->nullable(); $t->decimal('total_amount',14,2); $t->timestamps(); });
        Schema::create('pos_print_jobs', function (Blueprint $t) { $t->id(); $t->string('status'); $t->text('error')->nullable(); $t->timestamps(); });
        (require database_path('migrations/2026_10_03_170000_add_print_job_evidence.php'))->up();
        (require database_path('migrations/2026_12_21_090000_add_print_evidence_correlation.php'))->up();
        (require database_path('migrations/2026_12_22_090000_create_pra_diagnostic_samples.php'))->up();
    }

    private function read(string $stream, array $extra = [], ?array $scope = [1]): array
    {
        return app(PraOperationsEvidence::class)->read($extra + ['company_name'=>'Synthetic Cafe','date_from'=>'2026-11-02','date_to'=>'2026-11-03','stream'=>$stream], $scope);
    }

    private function bill(array $extra = []): int
    {
        return DB::table('pos_transactions')->insertGetId($extra + ['company_id'=>1,'branch_id'=>7,'business_date'=>'2026-11-02','status'=>'completed','transaction_type'=>'sale','invoice_mode'=>'pra','payment_method'=>'cash','total_amount'=>900,'rider_partial_paid'=>0,'created_at'=>'2026-11-02 22:00:00','updated_at'=>'2026-11-02 22:00:00','customer_phone'=>'PRIVATE-CONTACT']);
    }

    private function cashFixture(): int
    {
        $this->bill();
        $this->bill(['total_amount'=>100,'rider_id'=>8,'order_type'=>'delivery']);
        $this->bill(['total_amount'=>100,'transaction_type'=>'return']);
        $this->bill(['total_amount'=>200,'payment_method'=>'debit_card']);
        $prior = $this->bill(['total_amount'=>50,'business_date'=>'2026-11-01']);
        $this->bill(['company_id'=>2,'total_amount'=>99999]);
        $this->bill(['invoice_mode'=>'local','total_amount'=>8000]);
        DB::table('pos_rider_settlements')->insert(['company_id'=>1,'panel'=>'pra','business_date'=>'2026-11-02','total_amount'=>50,'allocation'=>json_encode([['bill_id'=>$prior,'amount'=>50,'business_date'=>'2026-11-01']]),'created_at'=>'2026-11-03 01:00:00']);
        DB::table('pos_day_openings')->insert(['company_id'=>1,'branch_id'=>7,'business_date'=>'2026-11-02','opening_cash'=>0]);
        return DB::table('pos_day_close_reports')->insertGetId(['company_id'=>1,'branch_id'=>7,'report_date'=>'2026-11-02','total_invoices'=>3,'returns_count'=>1,
            'cash_amount'=>800,'card_amount'=>200,'other_amount'=>0,'opening_float'=>0,'expected_cash'=>750,'counted_cash'=>750,'cash_variance'=>0,
            'rider_summary'=>json_encode(['cash_out'=>100,'cash_in'=>50]),'created_at'=>'2026-11-03 02:00:00']);
    }

    public function test_consistent_saved_arithmetic_can_hide_independent_cash_source_difference_without_writes(): void
    {
        $this->cashFixture();
        $before = DB::table('pos_day_close_reports')->get()->toJson();
        $old = app(DayCloseEvidence::class)->read(1,['date_from'=>'2026-11-02']);
        $this->assertSame('PASS',$old['records'][0]['arithmetic']['expected_check']);
        $r=$this->read('cash_reconciliation')['records'][0];
        $this->assertSame('STORED_SOURCE_DIFFERENCE',$r['status']);
        $this->assertEquals(900,$r['payments']['cash']['observed_net']);
        $this->assertEquals(200,$r['payments']['card']['observed_net']);
        $this->assertEquals(850,$r['cash']['observed_expected']);
        $this->assertEquals(-100,$r['cash']['frozen_minus_observed']);
        $this->assertSame('2026-11-02',$r['business_date']);
        $this->assertStringNotContainsString('PRIVATE',json_encode($r));
        $this->assertSame($before,DB::table('pos_day_close_reports')->get()->toJson());
    }

    public function test_working_archived_sources_partial_rider_payments_and_other_branch_are_preserved(): void
    {
        $this->cashFixture();
        DB::table('pos_transactions')->where('id',1)->update(['total_amount'=>800,'is_archived'=>true]);
        $this->bill(['branch_id'=>8,'total_amount'=>5000]);
        $r=$this->read('cash_reconciliation',['branch_id'=>7])['records'][0];
        $this->assertSame('STORED_SOURCES_MATCH',$r['status']);
        $this->assertEquals(750,$r['cash']['observed_expected']);
        DB::table('pos_transactions')->where('id',2)->update(['rider_partial_paid'=>40]);
        $r=$this->read('cash_reconciliation')['records'][0];
        $this->assertEquals(60,$r['cash']['observed_unsettled_delivery_cash']);
        $this->assertEquals(790,$r['cash']['observed_expected']);
    }

    public function test_deleted_later_changed_and_legacy_settlement_evidence_never_claims_a_complete_match(): void
    {
        $this->cashFixture();
        DB::table('pos_transactions')->where('id',1)->delete();
        DB::table('pos_transactions')->where('id',2)->update(['rider_settlement_id'=>99,'rider_settled_at'=>'2026-11-03 10:00:00']);
        DB::table('pos_rider_settlements')->where('id',1)->update(['business_date'=>null]);
        $r=$this->read('cash_reconciliation')['records'][0];
        $this->assertSame('PARTIAL',$r['status']);
        foreach (['frozen_and_available_document_counts_differ','legacy_receipt_business_date_unknown','rider_settlement_after_close_or_undated'] as $gap) $this->assertContains($gap,$r['gaps']);
    }

    public function test_missing_sources_zero_opening_pagination_and_scope_boundaries(): void
    {
        $this->cashFixture();
        $this->cashFixture();
        $r=$this->read('cash_reconciliation',['limit'=>1]);
        $this->assertTrue($r['pagination']['has_more']);
        $next=$this->read('cash_reconciliation',['limit'=>1,'after_id'=>$r['pagination']['next_after_id']]);
        $this->assertFalse($next['pagination']['has_more']);
        Schema::drop('pos_transactions');
        $this->assertSame('UNAVAILABLE',$this->read('cash_reconciliation')['records'][0]['status']);
        foreach ([['company_name'=>'Other Cafe'],['company_name'=>'FBR Cafe'],['date_from'=>'2026-10-01'],['limit'=>11],['arbitrary_sql'=>'x']] as $bad) {
            try { $this->read('cash_reconciliation',$bad); $this->fail('Invalid scope accepted'); } catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
    }

    private function evidence(int $job, ?int $attempt, string $event, array $context = [], int $company = 1): void
    {
        DB::table('pos_print_evidence')->insert(['company_id'=>$company,'product_type'=>'pos','job_id'=>$job,'attempt'=>$attempt,'event'=>$event,'transaction_id'=>10,'context'=>json_encode($context),'received_at'=>now()]);
    }

    public function test_print_attempts_do_not_hide_missing_results_behind_an_earlier_success(): void
    {
        $this->evidence(20,0,'enqueued'); $this->evidence(20,1,'claimed'); $this->evidence(20,1,'content_requested');
        $this->evidence(20,1,'result_received',['outcome'=>'spool_accepted','error'=>'PRIVATE']);
        $this->evidence(20,2,'claimed'); $this->evidence(20,2,'content_requested');
        $this->evidence(21,1,'result_received',['outcome'=>'agent_acknowledged']);
        $this->evidence(999,1,'claimed',[],2);
        $r=$this->read('print_chains',['limit'=>1]);
        $this->assertSame('SPOOL_ACCEPTED',$r['chains'][0]['attempts'][0]['state']);
        $this->assertSame('OUTPUT_UNKNOWN',$r['chains'][0]['attempts'][1]['state']);
        $this->assertSame('unknown',$r['physical_print']);
        $this->assertStringNotContainsString('PRIVATE',json_encode($r));
        $next=$this->read('print_chains',['limit'=>1,'after_id'=>$r['pagination']['next_after_id']]);
        $this->assertSame(21,$next['chains'][0]['job_id']); $this->assertFalse($next['pagination']['has_more']);
    }

    private function upload(array $events, array $extra = [], int $company = 1): ?array
    {
        return app(PraAgentDiagnosticEvidence::class)->ingest(Company::find($company),'PRIVATE-DEVICE',$extra + ['version'=>1,'stream_id'=>'11111111-1111-4111-8111-111111111111','events'=>$events,'pending'=>count($events),'dropped'=>0,'resets'=>0,'storage_status'=>'available']);
    }

    private function sample(int $sequence): array
    {
        return ['sequence'=>$sequence,'at'=>'2026-11-01T10:00:00.000Z','printing_enabled'=>true,'printer_healthy'=>false,'printers_reported'=>2,'pending_callbacks'=>4,'fiscal_state'=>'unknown','raw_error'=>'PRIVATE'];
    }

    public function test_offline_samples_dedupe_exact_content_preserve_clock_and_record_sequence_gaps(): void
    {
        $ack=$this->upload([$this->sample(1),$this->sample(3)]);
        $this->assertSame([1,3],$ack['accepted']);
        $this->assertSame([1,3],$this->upload([$this->sample(1),$this->sample(3)])['accepted']);
        $this->assertSame(2,DB::table('pra_diagnostic_samples')->count());
        $changed=$this->sample(1); $changed['pending_callbacks']=9;
        $conflict=$this->upload([$changed]);
        $this->assertSame('conflicting_sequence',$conflict['status']); $this->assertSame([],$conflict['accepted']);
        $r=$this->read('agent_collection');
        $this->assertSame(1,$r['streams'][0]['missing_sequences_between_retained_endpoints']);
        $this->assertGreaterThan(86400,$r['samples'][0]['clock_or_upload_delay_seconds']);
        $this->assertFalse($r['seven_day_complete_record_proven']);
        $this->assertStringNotContainsString('PRIVATE',json_encode($r));
        $this->assertNull($this->upload([$this->sample(1)],[],3));
        $this->upload([$this->sample(1)],[],2);
        $this->assertSame(2,$this->read('agent_collection')['observed']['matching_samples']);
    }

    public function test_retention_migration_retry_limits_and_unavailable_storage_never_acknowledge_lost_samples(): void
    {
        $this->assertNull($this->upload(array_fill(0,51,$this->sample(1))));
        $this->upload([$this->sample(1),$this->sample(2)],['dropped'=>5,'resets'=>1,'storage_status'=>'storage_unavailable']);
        DB::table('pra_diagnostic_samples')->where('sequence',1)->update(['received_at'=>now()->subDays(31)]);
        DB::table('pra_diagnostic_samples')->where('sequence',2)->update(['received_at'=>now()->subDays(7)]);
        (require database_path('migrations/2026_12_22_090000_create_pra_diagnostic_samples.php'))->up();
        $this->assertSame(1,PraAgentDiagnosticEvidence::prune()); $this->assertSame(0,PraAgentDiagnosticEvidence::prune());
        $this->assertSame(1,DB::table('pra_diagnostic_samples')->count());
        Schema::drop('pra_diagnostic_samples');
        $ack=$this->upload([$this->sample(3)]); $this->assertSame('unavailable',$ack['status']); $this->assertSame([],$ack['accepted']);
    }
    public function test_hotel_money_and_credit_note_refunds_are_counted_once_and_unknown_schema_is_not_zero(): void
    {
        $this->cashFixture();
        Schema::create('hotel_stays', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->boolean('hotel_money_from_folio'); });
        Schema::create('hotel_credit_notes', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('credit_transaction_id'); });
        Schema::create('hotel_folio_entries', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('stay_id'); $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('entry_type'); $t->decimal('amount',14,2); $t->string('payment_method'); $t->date('settlement_business_date')->nullable();
            $t->date('refund_business_date')->nullable(); $t->unsignedBigInteger('settlement_branch_id')->nullable(); $t->unsignedBigInteger('refund_branch_id')->nullable(); $t->timestamps();
        });
        DB::table('hotel_stays')->insert(['id'=>1,'company_id'=>1,'hotel_money_from_folio'=>true]);
        DB::table('hotel_credit_notes')->insert(['company_id'=>1,'credit_transaction_id'=>88]);
        $base = ['company_id'=>1,'stay_id'=>1,'settlement_business_date'=>'2026-11-02','settlement_branch_id'=>7,'created_at'=>'2026-11-02 22:00:00'];
        DB::table('hotel_folio_entries')->insert($base + ['entry_type'=>'payment','amount'=>300,'payment_method'=>'cash']);
        DB::table('hotel_folio_entries')->insert($base + ['entry_type'=>'refund','amount'=>100,'payment_method'=>'cash','pos_transaction_id'=>88,'refund_business_date'=>'2026-11-02','refund_branch_id'=>7]);
        DB::table('hotel_folio_entries')->insert(array_replace($base,['company_id'=>2,'entry_type'=>'payment','amount'=>99999,'payment_method'=>'cash']));
        $r=$this->read('cash_reconciliation')['records'][0];
        $this->assertEquals(200,$r['payments']['cash']['hotel_net_movements']);
        $this->assertEquals(1050,$r['cash']['observed_expected']);
        Schema::table('hotel_folio_entries', fn (Blueprint $t) => $t->dropColumn('refund_branch_id'));
        $r=$this->read('cash_reconciliation')['records'][0];
        $this->assertSame('PARTIAL',$r['status']);
        $this->assertNull($r['payments']['cash']['hotel_net_movements']);
        $this->assertNull($r['cash']['observed_expected']);
    }

    public function test_allocation_budget_bounds_queries_and_mismatches_remain_explicit(): void
    {
        $this->cashFixture();
        $entries = array_map(fn ($n) => ['bill_id'=>$n+1000,'amount'=>1,'business_date'=>'2026-11-01'],range(1,500));
        foreach (range(1,5) as $i) DB::table('pos_rider_settlements')->insert(['company_id'=>1,'panel'=>'pra','business_date'=>'2026-11-02',
            'allocation'=>json_encode($entries),'total_amount'=>500,'created_at'=>'2026-11-02 22:00:00']);
        DB::enableQueryLog();
        try {
            $r=$this->read('cash_reconciliation')['records'][0];
            $this->assertLessThan(100,count(DB::getQueryLog()),'No query per allocation');
            $this->assertSame('PARTIAL',$r['status']);
            $this->assertContains('receipt_bill_unavailable',$r['gaps']);
            $this->assertContains('receipt_allocation_limit',$r['gaps']);
        } finally { DB::disableQueryLog(); }
    }

    public function test_daily_coverage_clock_skew_and_empty_days_are_not_invented_as_complete(): void
    {
        config(['app.timezone'=>'UTC']);
        $this->upload([$this->sample(1)]);
        DB::table('pra_diagnostic_samples')->update(['received_at'=>'2026-11-01 19:30:00','source_event_at'=>'2026-11-04 12:00:00.123',
            'facts'=>json_encode(['pending_callbacks'=>'PRIVATE','fiscal_state'=>'PRIVATE'])]);
        $r=$this->read('agent_collection',['date_from'=>'2026-11-02','date_to'=>'2026-11-03']);
        $this->assertSame([1,0],array_column($r['received_days'],'samples'));
        $this->assertLessThan(0,$r['samples'][0]['clock_or_upload_delay_seconds']);
        $this->assertStringNotContainsString('PRIVATE',json_encode($r));
        $this->assertNull($r['samples'][0]['facts']['pending_callbacks']);
        $this->assertFalse($r['received_days'][1]['complete']);
        $this->evidence(40,1,'no_document');
        $this->assertSame('NO_DOCUMENT_OBSERVED',$this->read('print_chains')['chains'][0]['attempts'][0]['state']);
    }

}
