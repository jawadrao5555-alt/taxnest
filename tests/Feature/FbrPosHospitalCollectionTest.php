<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosHospitalController;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\User;
use App\Services\FbrService;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Synthetic records only. These checks do not constitute FBR sandbox acceptance. */
class FbrPosHospitalCollectionTest extends TestCase
{
    private Company $hospital;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Http::fake();
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag());
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type');
            $table->string('pos_type');
            $table->string('business_category')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->string('confidential_pin')->nullable();
            $table->boolean('fbr_pos_enabled')->default(true);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('pos_cashier_dayclose')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->string('fbr_connection_mode')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role');
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('school_fee_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('collected_by');
            $table->string('student_reference');
            $table->string('fee_head');
            $table->unsignedBigInteger('amount_paisa');
            $table->uuid('request_key');
            $table->timestamp('collected_at');
            $table->timestamps();
            $table->unique(['company_id', 'request_key']);
        });
        Schema::create('school_fee_day_closes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_key')->default(0);
            $table->date('report_date');
            $table->unsignedBigInteger('cash_amount_paisa');
            $table->unsignedInteger('receipt_count');
            $table->unsignedBigInteger('closed_by');
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->unique(['company_id', 'branch_key', 'report_date']);
        });
        (require database_path('migrations/2026_11_15_010000_extend_school_fee_collection_details.php'))->up();
        $this->hospital = Company::withoutEvents(fn () => Company::create([
            'name' => 'Synthetic Hospital', 'product_type' => 'fbrpos',
            'pos_type' => 'clinic', 'business_category' => 'clinic',
            'fbr_reporting_enabled' => false, 'is_internal_account' => true,
        ]));
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('active')->default(true);
            $table->string('override_type')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });
        DB::table('subscriptions')->insert([
            'company_id' => $this->hospital->id, 'active' => true, 'override_type' => 'lifetime',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cashier = User::withoutEvents(fn () => User::create([
            'company_id' => $this->hospital->id, 'name' => 'Synthetic Cashier',
            'email' => 'cashier@example.test', 'password' => 'not-a-real-password',
            'role' => 'employee', 'pos_role' => 'pos_cashier', 'is_active' => true,
        ]));
        (require database_path('migrations/2026_11_15_020000_create_hospital_collection_tables.php'))->up();
        (require database_path('migrations/2026_11_15_040000_extend_hospital_collection_ledger.php'))->up();
        $this->actingAs($this->cashier, 'fbrpos');
        app()->instance('currentCompanyId', $this->hospital->id);
        app()->bind('currentBranchId', fn () => null);
    }


    private function payload(array $overrides=[]): array {
        return array_replace(['request_key'=>(string)Str::uuid(),'expected_branch_key'=>app('currentBranchId')??0,
            'patient_reference'=>'SYN-P-001','service_head'=>'consultation','total_amount'=>'1000.00','amount'=>'400.00',
            'payment_method'=>'cash','payment_confirmed'=>'1'],$overrides);
    }
    private function request(array $d,string $path='/fbr-pos/hospital'): Request {
        $r=Request::create($path,'POST',$d,[],[],['HTTP_ACCEPT'=>'application/json']);
        $r->setLaravelSession(app('session.store'));app()->instance('request',$r);return $r;
    }
    private function collect(array $d,?int $invoice=null) {
        $c=app(FbrPosHospitalController::class);$r=$this->request($d);
        return $invoice===null?$c->store($r):$c->payment($r,$invoice);
    }
    private function conflict(callable $f,string $field): void {
        try{$f();$this->fail('Expected immutable validation conflict');}catch(ValidationException $e){$this->assertArrayHasKey($field,$e->errors());}
    }
    private function denied(callable $f,int $status): void {
        try{$f();$this->fail('Expected denied');}catch(HttpExceptionInterface $e){$this->assertSame($status,$e->getStatusCode());}
    }
    public function test_same_amount_walk_ins_are_distinct_and_retries_are_idempotent(): void
    {
        $first = $this->payload(['patient_reference' => '', 'patient_name' => '']);
        $this->collect($first);
        $this->collect($first);
        $this->collect($this->payload(['patient_reference' => '', 'patient_name' => '']));
        $this->assertSame(2, DB::table('hospital_payments')->count());
        $this->assertSame(2, DB::table('hospital_invoices')->distinct()->count('patient_reference'));
        $this->assertSame(2, DB::table('hospital_invoices')->whereNull('patient_name')->count());
    }

    public function test_owner_corrects_and_voids_local_invoice_but_cannot_void_an_ims_receipt(): void
    {
        (require database_path('migrations/2027_01_01_000000_add_pilot_fiscal_route_snapshots.php'))->up();
        (require database_path('migrations/2027_01_01_000005_create_hospital_invoice_corrections.php'))->up();
        $original = $this->payload();
        $this->collect($original);
        $this->collect($this->payload());
        $fiscal = DB::table('hospital_payments')->orderByDesc('id')->first();
        DB::table('hospital_payments')->where('id', $fiscal->id)->update([
            'fiscal_route_snapshot' => json_encode(['route' => 'ims', 'eligible' => true]),
        ]);
        $local = DB::table('hospital_payments')->orderBy('id')->first();
        $correction = ['action' => 'edit', 'expected_branch_key' => 0, 'reason' => 'Synthetic correction',
            'patient_name' => '', 'total_amount' => '500.00', 'payment_amount' => '450.00'];
        $controller = app(FbrPosHospitalController::class);
        $this->denied(fn () => $controller->correctLocalInvoice($this->request($correction), $local->invoice_id), 403);
        $this->cashier->forceFill(['role' => 'company_admin', 'pos_role' => null]);
        $this->actingAs($this->cashier, 'fbrpos');
        $controller->correctLocalInvoice($this->request($correction), $local->invoice_id);
        $this->assertDatabaseHas('hospital_payments', ['id' => $local->id, 'amount_paisa' => 45000, 'balance_paisa' => 5000]);
        $void = array_replace($correction, ['action' => 'void']);
        $this->denied(fn () => $controller->correctLocalInvoice($this->request($void), $fiscal->invoice_id), 409);
        $controller->correctLocalInvoice($this->request($void), $local->invoice_id);
        $this->assertDatabaseMissing('hospital_payments', ['id' => $local->id]);
        $this->assertDatabaseHas('hospital_invoice_corrections', ['invoice_id' => $local->invoice_id, 'action' => 'void']);
        $this->conflict(fn () => $this->collect($original), 'request_key');
    }

    public function test_dashboard_separates_daily_collections_and_scoped_outstanding(): void
    {
        $this->withoutVite();
        $this->collect($this->payload(['payment_method'=>'bank','payment_reference'=>'SYNTHETIC-BANK-01']));
        $invoice=DB::table('hospital_invoices')->first();
        // Corrupt foreign payment must never reduce this campus/company balance.
        $foreign=(array)DB::table('hospital_payments')->first(); unset($foreign['id']);
        $foreign['company_id']=999; $foreign['request_key']=(string)Str::uuid(); $foreign['amount_paisa']=50000;
        DB::table('hospital_payments')->insert($foreign);
        $response=app(FbrPosHospitalController::class)->dashboard();
        $data=$response->original->getData();
        $this->assertSame(60000,$data['outstanding']);
        $this->assertSame(40000,$data['totals']['bank_paisa']);
        $this->assertSame(0,$data['totals']['cash_paisa']);
        $this->assertSame(1,$data['totals']['receipt_count']);
        $this->assertSame('Synthetic Hospital',$data['campusName']);
        $html=$response->getContent();
        $this->assertStringContainsString('hospital-outstanding',$html);
        $this->assertStringContainsString('data-paisa="60000"',$html);
        $this->assertStringContainsString('Fiscal OFF',$html);
        Http::assertNothingSent();
    }

    public function test_partial_payments_are_immutable_and_replay_safe_with_correct_balance(): void {
        $first=$this->payload();$id=$this->collect($first)->getData(true)['receipt_id'];
        $invoice=DB::table('hospital_invoices')->first();$original=DB::table('hospital_payments')->find($id);
        $this->assertSame(60000,(int)$original->balance_paisa);
        $this->assertSame($id,$this->collect($first)->getData(true)['receipt_id']);
        foreach(['amount'=>'401','patient_reference'=>'OTHER','service_head'=>'lab','total_amount'=>'1100','payment_method'=>'card'] as $k=>$v)
            $this->conflict(fn()=>$this->collect(array_replace($first,[$k=>$v])),'request_key');
        $second=$this->payload(['amount'=>'300','payment_method'=>'card']);
        $secondId=$this->collect($second,$invoice->id)->getData(true)['receipt_id'];
        $third=$this->payload(['amount'=>'300','payment_method'=>'bank']);
        $thirdId=$this->collect($third,$invoice->id)->getData(true)['receipt_id'];
        $this->assertSame(30000,(int)DB::table('hospital_payments')->find($secondId)->balance_paisa);
        $this->assertSame(0,(int)DB::table('hospital_payments')->find($thirdId)->balance_paisa);
        $this->assertEquals($original,DB::table('hospital_payments')->find($id));
        $this->conflict(fn()=>$this->collect($this->payload(['amount'=>'0.01']),$invoice->id),'amount');
        $this->assertSame(1,DB::table('hospital_invoices')->count());$this->assertSame(3,DB::table('hospital_payments')->count());
        $this->assertSame(100000,(int)DB::table('hospital_payments')->sum('amount_paisa'));
        $html=app(FbrPosHospitalController::class)->receipt($id)->getContent();
        $this->assertStringNotContainsString('tnMadadgar(', $html);
        $this->assertStringNotContainsString('alt="FBR QR Code"', $html);
        $this->assertStringNotContainsString('class="fbr-number"', $html);
        $this->assertStringNotContainsString('data-fiscal-qr', $html);
        foreach(['SYN-P-001','Consultation','400.00','600.00','Synthetic Cashier','Synthetic Hospital','FBR OFF'] as $v)$this->assertStringContainsString($v,$html);
        Http::assertNothingSent();
    }
    public function test_service_heads_methods_and_payment_confirmation(): void {
        foreach(['consultation','lab','procedure'] as $head){$this->collect($this->payload(['service_head'=>$head]));}
        $this->conflict(fn()=>$this->collect($this->payload(['payment_confirmed'=>'0'])),'payment_confirmed');
        foreach(['amount'=>'0','service_head'=>'retail','payment_method'=>'crypto'] as $k=>$v)
            $this->conflict(fn()=>$this->collect($this->payload([$k=>$v])),$k);
        $this->assertSame(3,DB::table('hospital_invoices')->count());
    }
    public function test_receipt_and_invoice_tenant_campus_and_role_isolation(): void {
        app()->instance('currentBranchId',17);$id=$this->collect($this->payload())->getData(true)['receipt_id'];
        $invoice=DB::table('hospital_invoices')->first();
        app()->instance('currentBranchId',18);
        $this->denied(fn()=>app(FbrPosHospitalController::class)->receipt($id),404);
        $this->denied(fn()=>app(FbrPosHospitalController::class)->invoice($invoice->id),404);
        $this->conflict(fn()=>$this->collect($this->payload(['expected_branch_key'=>17])),'expected_branch_key');
        app()->instance('currentBranchId',17);
        $this->hospital->is_internal_account=true;$this->hospital->save();
        DB::table('hospital_payments')->where('id',$id)->update(['company_id'=>999]);
        $this->denied(fn()=>app(FbrPosHospitalController::class)->receipt($id),404);
        $this->cashier->pos_role='waiter';$this->cashier->save();
        $this->denied(fn()=>app(FbrPosHospitalController::class)->index(),403);
    }
    public function test_readonly_and_retail_company_cannot_collect(): void {
        session(['impersonation.readonly'=>true]);$this->denied(fn()=>$this->collect($this->payload()),403);session()->forget('impersonation.readonly');
        $this->hospital->update(['pos_type'=>'retail','business_category'=>'retail']);$this->denied(fn()=>$this->collect($this->payload()),404);
        $this->assertSame(0,DB::table('hospital_payments')->count());
    }
    private function closePayload(): array {
        return ['date'=>today()->toDateString(),'confirm'=>'1','expected_branch_key'=>app('currentBranchId')??0,
            'expected_cash_paisa'=>40000,'expected_card_paisa'=>30000,'expected_bank_paisa'=>30000,'expected_receipt_count'=>3,
            'counted_cash'=>'399.00','variance_note'=>'Synthetic count shortage'];
    }
    public function test_close_snapshot_freezes_separate_methods_variance_and_allows_saved_recovery(): void {
        $this->hospital->update(['pos_cashier_dayclose'=>true]);
        $first=$this->payload();$id=$this->collect($first)->getData(true)['receipt_id'];$invoice=DB::table('hospital_invoices')->first();
        $this->collect($this->payload(['amount'=>'300','payment_method'=>'card']),$invoice->id);
        $this->collect($this->payload(['amount'=>'300','payment_method'=>'bank']),$invoice->id);
        $close=$this->closePayload();$c=app(FbrPosHospitalController::class);
        $this->conflict(fn()=>$c->close($this->request(array_replace($close,['expected_cash_paisa'=>0]))),'date');
        $this->conflict(fn()=>$c->close($this->request(array_replace($close,['expected_branch_key'=>99]))),'expected_branch_key');
        $this->conflict(fn()=>$c->close($this->request(array_replace($close,['variance_note'=>'']))),'variance_note');
        $report=$c->close($this->request($close))->getData(true)['report_id'];$original=DB::table('hospital_day_closes')->find($report);
        $this->assertSame(-100,(int)$original->variance_paisa);$this->assertSame(40000,(int)$original->cash_paisa);
        $this->assertSame(30000,(int)$original->card_paisa);$this->assertSame(30000,(int)$original->bank_paisa);
        $this->assertSame($report,$c->close($this->request($close))->getData(true)['report_id']);
        $this->conflict(fn()=>$c->close($this->request(array_replace($close,['counted_cash'=>'400']))),'counted_cash');
        $this->assertSame($id,$this->collect(array_replace($first,['payment_confirmed'=>'0']))->getData(true)['receipt_id']);
        $this->conflict(fn()=>$this->collect($this->payload()),'amount');
        $this->assertEquals($original,DB::table('hospital_day_closes')->find($report));
        $this->assertSame(3,DB::table('hospital_payments')->count());Http::assertNothingSent();
    }
    public function test_bill_quota_counts_invoices_not_installments_and_replay_survives_cap(): void {
        $this->hospital->update(['is_internal_account'=>false,'invoice_limit_override'=>1]);
        $first=$this->payload();$id=$this->collect($first)->getData(true)['receipt_id'];$invoice=DB::table('hospital_invoices')->first();
        $this->assertFalse(\App\Services\PlanLimitService::canCreateFbrPosBill($this->hospital->id)['allowed']);
        $this->collect($this->payload(['amount'=>'600','payment_method'=>'card']),$invoice->id);
        $this->assertSame($id,$this->collect($first)->getData(true)['receipt_id']);
        $this->denied(fn()=>$this->collect($this->payload()),403);
        $this->assertSame(1,DB::table('hospital_invoices')->count());$this->assertSame(2,DB::table('hospital_payments')->count());
    }
    public function test_existing_invoice_settlement_ignores_only_bill_cap_not_missing_subscription(): void {
        DB::table('subscriptions')->where('company_id',$this->hospital->id)->update(['override_type'=>'usage_free','free_invoice_limit'=>1]);
        $first=$this->payload();$receipt=$this->collect($first)->getData(true)['receipt_id'];$invoice=DB::table('hospital_invoices')->first();
        $this->assertFalse(\App\Services\SubscriptionAccessService::hasAccess($this->hospital)['allowed']);
        $this->assertTrue(\App\Services\SubscriptionAccessService::hasAccess($this->hospital,true)['allowed']);
        $this->collect($this->payload(['amount'=>'300','payment_method'=>'bank']),$invoice->id);
        DB::table('subscriptions')->where('company_id',$this->hospital->id)->update(['active'=>false]);
        $this->denied(fn()=>$this->collect($this->payload(['amount'=>'300']),$invoice->id),403);
        $this->assertSame($receipt,$this->collect($first)->getData(true)['receipt_id']);
        $this->assertSame(2,DB::table('hospital_payments')->count());
    }
    public function test_quota_middleware_routes_saved_key_to_immutable_controller_checks(): void {
        $first=$this->payload();$id=$this->collect($first)->getData(true)['receipt_id'];
        DB::table('subscriptions')->where('company_id',$this->hospital->id)->update(['active'=>false]);
        $middleware=app(\App\Http\Middleware\CheckPlanLimit::class);
        $response=$middleware->handle($this->request($first),fn($r)=>app(FbrPosHospitalController::class)->store($r),'invoices');
        $this->assertSame($id,$response->getData(true)['receipt_id']);
        $this->conflict(fn()=>$middleware->handle($this->request(array_replace($first,['amount'=>'401'])),fn($r)=>app(FbrPosHospitalController::class)->store($r),'invoices'),'request_key');
    }
    public function test_saved_receipt_names_and_balance_do_not_follow_later_renames_or_payments(): void {
        $id=$this->collect($this->payload())->getData(true)['receipt_id'];$before=DB::table('hospital_payments')->find($id);
        $this->cashier->update(['name'=>'Renamed synthetic cashier']);$this->hospital->update(['name'=>'Renamed hospital']);
        $this->collect($this->payload(['amount'=>'100']),$before->invoice_id);
        $after=DB::table('hospital_payments')->find($id);$this->assertEquals($before,$after);
        $this->assertSame('Synthetic Cashier',$after->cashier_name);$this->assertSame('Synthetic Hospital',$after->campus_name);
        $this->assertSame(60000,(int)$after->balance_paisa);
    }
    public function test_close_requires_permission_and_cannot_run_in_readonly_session(): void {
        $c=app(FbrPosHospitalController::class);$this->denied(fn()=>$c->close($this->request($this->closePayload())),403);
        $this->hospital->update(['pos_cashier_dayclose'=>true]);session(['impersonation.readonly'=>true]);
        $this->denied(fn()=>$c->close($this->request($this->closePayload())),403);
        $this->assertSame(0,DB::table('hospital_day_closes')->count());
    }

    public function test_first_campus_preserves_owner_only_unassigned_debt_settlement_and_closing(): void {
        $this->cashier->role='company_admin';$this->cashier->pos_role='pos_admin';$this->actingAs($this->cashier,'fbrpos');
        $id=$this->collect($this->payload())->getData(true)['receipt_id'];$invoice=DB::table('hospital_invoices')->first();
        Schema::create('branches',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('name');$t->boolean('is_active')->default(true);$t->boolean('is_head_office')->default(false);$t->timestamps();});
        DB::table('branches')->insert(['id'=>17,'company_id'=>$this->hospital->id,'name'=>'First campus','is_active'=>true]);
        app()->forgetInstance(\App\Services\BranchContextService::class);$c=app(FbrPosHospitalController::class);
        app()->instance('request',Request::create('/fbr-pos/hospital/history'));
        $this->denied(fn()=>$c->invoice($invoice->id),404);
        $r=Request::create('/fbr-pos/hospital/history','GET',['unassigned'=>'1']);app()->instance('request',$r);
        $this->assertStringContainsString('HI-'.$invoice->id,$c->history($r)->getContent());
        $this->assertSame(200,$c->invoice($invoice->id)->getStatusCode());$this->assertSame(200,$c->receipt($id)->getStatusCode());
        $p=$this->payload(['unassigned'=>'1','expected_branch_key'=>0,'amount'=>'600']);
        $receipt=$this->collect($p,$invoice->id)->getData(true);$this->assertStringEndsWith('?unassigned=1',$receipt['receipt_url']);
        $this->assertNull(DB::table('hospital_payments')->find($receipt['receipt_id'])->branch_id);
        $this->denied(fn()=>$this->collect($this->payload(['unassigned'=>'1','expected_branch_key'=>0])),403);
        $close=array_replace($this->closePayload(),['unassigned'=>'1','expected_branch_key'=>0,'expected_receipt_count'=>2,
            'expected_cash_paisa'=>100000,'expected_card_paisa'=>0,'expected_bank_paisa'=>0,'counted_cash'=>'1000','variance_note'=>'']);
        $c->close($this->request($close));$this->assertSame(0,(int)DB::table('hospital_day_closes')->value('branch_key'));
        $this->assertNull(DB::table('hospital_invoices')->find($invoice->id)->branch_id);
        $this->cashier->role='employee';$this->cashier->pos_role='pos_cashier';$this->actingAs($this->cashier,'fbrpos');
        app()->forgetInstance(\App\Services\BranchContextService::class);app()->instance('request',$r);
        $this->denied(fn()=>$c->history($r),403);
        $this->denied(fn()=>$this->collect($p,$invoice->id),403);
    }


    public function test_multi_service_snapshots_patient_lookup_and_bank_reference_replay(): void {
        DB::table('hospital_services')->insert(['company_id'=>$this->hospital->id,'code'=>'custom','label'=>'Synthetic scan','service_head'=>'lab','rate_paisa'=>50000,'active'=>true]);
        $payload=$this->payload(['patient_name'=>'Synthetic Patient','payment_method'=>'bank','payment_reference'=>'SYN-BANK-1',
            'service_lines'=>[['service_head'=>'custom','amount'=>'500'],['service_head'=>'consultation','amount'=>'500']]]);
        $id=$this->collect($payload)->getData(true)['receipt_id'];
        $invoice=DB::table('hospital_invoices')->first();
        $lines=json_decode($invoice->service_lines,true);
        $this->assertSame('Synthetic scan',$lines[0]['label']);
        $this->assertSame('Synthetic Patient',$invoice->patient_name);
        $this->assertSame('SYN-BANK-1',DB::table('hospital_payments')->find($id)->payment_reference);
        DB::table('hospital_services')->where('code','custom')->update(['label'=>'Changed','active'=>false]);
        $this->assertSame($id,$this->collect($payload)->getData(true)['receipt_id']);
        $this->conflict(fn()=>$this->collect(array_replace($payload,['payment_reference'=>'CHANGED'])),'request_key');
        $this->conflict(fn()=>$this->collect(array_replace($payload,['patient_name'=>'Changed'])),'request_key');
        $changed=$payload; $changed['service_lines'][0]['amount']='501';$changed['service_lines'][1]['amount']='499';
        $this->conflict(fn()=>$this->collect($changed),'request_key');
        $this->assertSame('Synthetic scan',json_decode(DB::table('hospital_invoices')->first()->service_lines,true)[0]['label']);
        $this->conflict(fn()=>$this->collect(array_replace($payload,['request_key'=>(string)Str::uuid()])),'service_lines');
        $html=app(FbrPosHospitalController::class)->index()->getContent();
        $this->assertStringContainsString('tnMadadgar(', $html);
        $this->assertStringContainsString('tnMadadgar(', app(FbrPosHospitalController::class)->dashboard()->getContent());
        $this->assertStringContainsString('Synthetic Patient',$html);
    }

    public function test_history_filters_displayed_invoice_number_dates_paid_balance_and_settled_state(): void {
        $this->collect($this->payload()); $invoice=DB::table('hospital_invoices')->first();
        $controller=app(FbrPosHospitalController::class);
        $history=function(array $filters)use($controller){$r=Request::create('/fbr-pos/hospital/history','GET',$filters);app()->instance('request',$r);return $controller->history($r)->original->getData()['invoices'];};
        $this->assertCount(1,$history(['search'=>'HI-'.$invoice->id,'status'=>'partial','paid_min'=>'400','paid_max'=>'400','balance_min'=>'600','balance_max'=>'600']));
        $this->assertCount(0,$history(['status'=>'settled']));
        $this->assertCount(0,$history(['to'=>today()->subDay()->format('Y-m-d')]));
        $this->collect($this->payload(['amount'=>'600','payment_method'=>'card','payment_reference'=>'SYN-CARD']),$invoice->id);
        $rows=$history(['search'=>'HI-'.$invoice->id,'status'=>'settled','balance_max'=>'0']);
        $this->assertCount(1,$rows);$this->assertSame(100000,(int)$rows->first()->paid_paisa);$this->assertSame(0,(int)$rows->first()->balance_paisa);
        $this->assertCount(0,$history(['status'=>'partial']));
        DB::table('hospital_payments')->insert(['company_id'=>$this->hospital->id+99,'branch_id'=>null,'invoice_id'=>$invoice->id,'request_key'=>(string)Str::uuid(),'amount_paisa'=>1,'payment_method'=>'cash','balance_paisa'=>0,'collected_by'=>$this->cashier->id,'cashier_name'=>'Other','campus_name'=>'Other','collected_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $this->assertCount(1,$history(['status'=>'settled']));
    }

    public function test_new_payment_receipts_freeze_independent_routes_without_rewriting_legacy_bill_or_receipt(): void
    {
        (require database_path('migrations/2027_01_01_000000_add_pilot_fiscal_route_snapshots.php'))->up();
        Http::fake();
        $payload=$this->payload();
        $firstId=$this->collect($payload)->getData(true)['receipt_id'];
        $invoice=DB::table('hospital_invoices')->first();
        $first=DB::table('hospital_payments')->find($firstId);
        $snapshot=json_decode($first->fiscal_route_snapshot,true);
        $this->assertSame(2,$snapshot['version']);
        $this->assertSame('hospital_payment',$snapshot['source']);
        $this->assertSame((int)$invoice->id,$snapshot['invoice_id']);
        $this->assertSame('off',$snapshot['route']);
        $this->assertNull($invoice->fiscal_route_snapshot);
        $this->assertSame($firstId,$this->collect($payload)->getData(true)['receipt_id']);
        $this->assertEquals($first,DB::table('hospital_payments')->find($firstId));
        // Simulate a historical inherited bill route; it must not arm a new receipt.
        $old=json_decode(\App\Services\PilotFiscalRouteSnapshot::newBill($this->hospital,'hospital','hospital_invoice'),true);
        $old['route']='ims';$raw=json_encode($old);
        DB::table('hospital_invoices')->where('id',$invoice->id)->update(['fiscal_route_snapshot'=>$raw]);
        DB::table('hospital_payments')->where('id',$firstId)->update(['fiscal_route_snapshot'=>$raw]);
        $legacy=DB::table('hospital_payments')->find($firstId);
        $this->assertSame('ims',\App\Services\PilotFiscalRouteSnapshot::recoveryPlan($this->hospital->id,'hospital','hospital_payment',$firstId)['route']);
        $nextPayload=$this->payload(['amount'=>'600']);
        $secondId=$this->collect($nextPayload,$invoice->id)->getData(true)['receipt_id'];
        $second=DB::table('hospital_payments')->find($secondId);
        $next=json_decode($second->fiscal_route_snapshot,true);
        $this->assertSame(2,$next['version']);$this->assertSame('off',$next['route']);
        $this->assertSame('local_only',\App\Services\PilotFiscalRouteSnapshot::recoveryPlan($this->hospital->id,'hospital','hospital_payment',$secondId)['state']);
        $this->assertSame(0,(int)$second->balance_paisa);
        $this->assertSame($secondId,$this->collect($nextPayload,$invoice->id)->getData(true)['receipt_id']);
        $this->assertEquals($legacy,DB::table('hospital_payments')->find($firstId));
        $this->assertEquals($second,DB::table('hospital_payments')->find($secondId));
        $this->assertSame($raw,DB::table('hospital_invoices')->where('id',$invoice->id)->value('fiscal_route_snapshot'));
        // Legacy receipt without provenance remains unknown, never backfilled by retry.
        DB::table('hospital_payments')->where('id',$firstId)->update(['fiscal_route_snapshot'=>null]);
        $this->collect($payload);
        $this->assertNull(DB::table('hospital_payments')->where('id',$firstId)->value('fiscal_route_snapshot'));
        Http::assertNothingSent();
    }
}
