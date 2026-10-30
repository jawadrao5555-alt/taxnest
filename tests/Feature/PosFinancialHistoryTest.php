<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Reconciled sales survive deletion policy without retaining recoverable bill details. */
class PosFinancialHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('pos_billing_scope')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number')->nullable();
            $table->integer('deleted_final_count')->default(0);
            $table->integer('deleted_provisional_count')->default(0);
            $table->text('local_summary')->nullable();
            $table->text('rider_summary')->nullable();
            $table->text('stream_summary')->nullable();
            $table->text('returns_detail')->nullable();
            $table->integer('total_invoices')->default(0);
            $table->integer('pra_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('offline_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
            $table->decimal('other_amount', 14, 2)->default(0);
            $table->integer('returns_count')->default(0);
            $table->decimal('returns_amount', 14, 2)->default(0);
            $table->string('first_invoice_number')->nullable();
            $table->string('last_invoice_number')->nullable();
            $table->timestamp('first_invoice_time')->nullable();
            $table->timestamp('last_invoice_time')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('hash')->nullable();
            $table->decimal('opening_float', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('restaurant_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('business_date');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Historical Archived Co',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_dayclose_provisional_action' => 'save',
            'pos_dayclose_final_local_action' => 'save',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function makePosUser(int $companyId): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'POS Admin',
            'email' => 'admin' . $companyId . '@taxnest.test',
            'company_id' => $companyId,
            'role' => 'user',
            'pos_role' => 'pos_admin',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function makeTxn(int $companyId, string $number, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $number,
            'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }


    private function history(int $companyId, string $from, string $to, string $tab = 'local', mixed $cashier = 'all', ?User $user = null): array
    {
        return \App\Services\PosFinancialHistory::report($companyId, $from, $to, $tab, $cashier, $user,
            function ($q) use ($tab, $cashier) {
                if ($tab !== 'combined') PosTransaction::applyStreamTab($q, $tab);
                if ($cashier !== 'all') $q->where('created_by', $cashier);
            });
    }

    private function migrateHistory(): void
    {
        (require database_path('migrations/2026_12_21_000004_add_pos_history_aggregates.php'))->up();
        (require database_path('migrations/2026_12_21_000003_add_pos_deal_allocated_bases.php'))->up();
    }

    public function test_actual_day_close_preserves_accounting_once_without_customer_or_invoice_copy(): void
    {
        $this->migrateHistory();
        $company = $this->makeCompany(['pos_dayclose_final_local_action'=>'delete', 'pos_customer_spend_persist'=>false]);
        $owner = $this->makePosUser($company); Auth::guard('pos')->setUser($owner); app()->instance('currentCompanyId', $company);
        $date = now()->toDateString();
        $bill = $this->makeTxn($company, 'PRIVATE-BILL-REF', ['pra_status'=>null,'pra_invoice_number'=>null,
            'subtotal'=>500,'tax_amount'=>80,'total_amount'=>580,'created_by'=>$owner->id,'customer_name'=>'PRIVATE CUSTOMER', 'customer_phone'=>'PRIVATE PHONE']);
        DB::table('pos_transaction_items')->insert(['transaction_id'=>$bill,'item_type'=>'product','item_id'=>44,'item_name'=>'Tea',
            'quantity'=>2,'unit_price'=>250,'subtotal'=>500,'tax_amount'=>80,
            'reporting_snapshot'=>json_encode(['category'=>'Drinks','size'=>'Large'])]);
        $before = $this->history($company,$date,$date);
        $this->assertEquals(580,$before['summary']['net']);
        $close = (new PosController())->performDayClose($company,$date,$owner->id);
        $this->assertSame('created',$close['status']);
        $this->assertSame(0,PosTransaction::withoutGlobalScope('hide_archived')->count());
        $after = $this->history($company,$date,$date);
        $this->assertSame($before['summary'],$after['summary']);
        $this->assertSame($before['products'],$after['products']);
        $this->assertSame(1,$after['retained_count']); $this->assertTrue($after['complete']);
        $this->assertEquals(580,array_sum(array_column($after['products'],'net')));
        $raw=json_encode(DB::table('pos_deleted_history_totals')->get());
        foreach(['PRIVATE-BILL-REF','PRIVATE CUSTOMER','PRIVATE PHONE','invoice_number','customer_phone'] as $private) $this->assertStringNotContainsString($private,$raw);
        $again=(new PosController())->performDayClose($company,$date,$owner->id);
        $this->assertSame('exists',$again['status']);
        $this->assertSame(1,DB::table('pos_deleted_history_totals')->count());
        $this->assertSame($after['summary'],$this->history($company,$date,$date)['summary']);
    }

    public function test_deleted_backlog_uses_original_business_date_and_cashier_with_tenant_isolation(): void
    {
        $this->migrateHistory(); $company=$this->makeCompany(); $other=$this->makeCompany(); $date='2026-10-28';
        $one=$this->makeTxn($company,'QA-ONE',['business_date'=>$date,'created_at'=>'2026-10-29 00:01:00','pra_status'=>null,'pra_invoice_number'=>null,'total_amount'=>300,'created_by'=>7]);
        $two=$this->makeTxn($company,'QA-TWO',['business_date'=>$date,'pra_status'=>null,'pra_invoice_number'=>null,'total_amount'=>90,'created_by'=>8,'payment_method'=>'card']);
        $foreign=$this->makeTxn($other,'QA-FOREIGN',['business_date'=>$date,'pra_status'=>null,'pra_invoice_number'=>null,'total_amount'=>9999,'created_by'=>7]);
        DB::transaction(function() use($one,$two,$foreign) {
            $rows=PosTransaction::whereIn('id',[$one,$two,$foreign])->get();
            \App\Services\PosFinancialHistory::captureDeleted($rows,null,'archive-clear');
            PosTransaction::whereIn('id',[$one,$two,$foreign])->delete();
        });
        $this->assertEquals(390,$this->history($company,$date,$date)['summary']['net']);
        $own=$this->history($company,$date,$date,'local',7);
        $this->assertEquals(300,$own['summary']['net']);
        $this->assertArrayHasKey($date,$own['daily']);
        $this->assertEquals(0,$this->history($company,'2026-10-29','2026-10-29')['summary']['net']);
        $this->assertEquals(0,$this->history($company,$date,$date,'pra')['summary']['net']);
        $this->assertEquals(9999,$this->history($other,$date,$date)['summary']['net']);
    }

    public function test_gross_returns_net_provisional_and_legacy_missing_evidence_are_distinct(): void
    {
        $this->migrateHistory(); $company=$this->makeCompany();$date=now()->toDateString();
        foreach([['sale',500,'pra',null],['return',120,'pra',null],['sale',800,'local','local']] as [$type,$amount,$mode,$status])
            $this->makeTxn($company,'QA-'.$amount,['transaction_type'=>$type,'total_amount'=>$amount,'invoice_mode'=>$mode,'pra_status'=>$status,'pra_invoice_number'=>null]);
        $report=$this->history($company,$date,$date);
        $this->assertEquals(500,$report['summary']['gross']);$this->assertEquals(120,$report['summary']['returned']);$this->assertEquals(380,$report['summary']['net']);
        $this->assertEquals(800,$report['provisional']['net']);
        DB::table('pos_day_close_reports')->insert(['company_id'=>$company,'report_date'=>$date,'report_number'=>'ZRPT-OLD','deleted_final_count'=>3,'total_amount'=>1600]);
        $report=$this->history($company,$date,$date);
        $this->assertFalse($report['complete']);$this->assertCount(1,$report['gaps']);
        $this->assertEquals(380,$report['summary']['net'],'Missing totals must not be invented from an unrelated Z scope.');
    }

    public function test_mixed_deal_and_size_groups_net_returns_without_parent_double_count(): void
    {
        $this->migrateHistory();Schema::table('pos_transaction_items',fn(Blueprint $t)=>$t->json('deal_snapshot')->nullable());
        $company=$this->makeCompany();$date=now()->toDateString();
        $snapshot=\App\Services\PosDealAllocation::capture([
            ['allocation_version'=>1,'allocation_weight'=>800,'product_id'=>1,'name'=>'Meal','qty'=>1,'category'=>'Food','size'=>'Regular','tax_facts'=>['is_tax_exempt'=>false]],
            ['allocation_version'=>1,'allocation_weight'=>200,'product_id'=>2,'name'=>'Drink','qty'=>1,'category'=>'Drinks','size'=>'Large','tax_facts'=>['is_tax_exempt'=>true]],
        ],1600,160,16,false);
        $bill=$this->makeTxn($company,'QA-DEAL',['pra_status'=>null,'pra_invoice_number'=>null,'total_amount'=>1624,'subtotal'=>1600,'discount_amount'=>160,'tax_amount'=>184]);
        DB::table('pos_transaction_items')->insert(['transaction_id'=>$bill,'item_type'=>'deal','item_id'=>5,'item_name'=>'Bundle','quantity'=>2,'subtotal'=>1600,'deal_snapshot'=>json_encode($snapshot)]);
        $return=$this->makeTxn($company,'QA-RETURN',['transaction_type'=>'return','parent_transaction_id'=>$bill,'pra_status'=>null,'pra_invoice_number'=>null,'total_amount'=>812,'subtotal'=>800,'discount_amount'=>80,'tax_amount'=>92]);
        DB::table('pos_transaction_items')->insert(['transaction_id'=>$return,'item_type'=>'deal','item_id'=>5,'item_name'=>'Bundle','quantity'=>1,'subtotal'=>800,'deal_snapshot'=>json_encode(\App\Services\PosDealAllocation::returnSlice($snapshot,2,0,1))]);
        $small=$this->makeTxn($company,'QA-SMALL',['pra_status'=>null,'pra_invoice_number'=>null,'total_amount'=>30,'subtotal'=>30]);
        DB::table('pos_transaction_items')->insert(['transaction_id'=>$small,'item_type'=>'product','item_id'=>3,'item_name'=>'Drink','quantity'=>1,'subtotal'=>30,'reporting_snapshot'=>json_encode(['category'=>'Drinks','size'=>'Small'])]);
        $report=$this->history($company,$date,$date);
        $this->assertEquals(842,$report['summary']['net']);
        $this->assertEquals(842,array_sum(array_column($report['products'],'net')));
        $drinks=collect($report['products'])->where('category','Drinks');$this->assertCount(2,$drinks);
        $this->assertEquals(1,$drinks->where('size','Large')->first()['qty']);
        $this->assertSame('deal component',$drinks->where('size','Large')->first()['channel']);
        $this->assertSame('standalone',$drinks->where('size','Small')->first()['channel']);
        $this->assertFalse(collect($report['products'])->contains('name','Bundle'));
    }

    public function test_report_range_rejects_invalid_calendar_date(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method=new \ReflectionMethod(PosController::class,'resolveReportRange');
        $method->invoke(new PosController(),Request::create('/pos/reports','GET',['from'=>'2026-02-31']));
    }
    public function test_html_and_csv_share_scope_and_reconciled_amounts(): void
    {
        $this->migrateHistory();$company=$this->makeCompany(['is_internal_account'=>true]);$owner=$this->makePosUser($company);
        $owner->pos_billing_scope='both';$owner->save();Auth::guard('pos')->setUser($owner);app()->instance('currentCompanyId',$company);
        $date=now()->toDateString();
        $this->makeTxn($company,'QA-LOCAL',['pra_status'=>null,'pra_invoice_number'=>null,'total_amount'=>150,'created_by'=>$owner->id]);
        $this->makeTxn($company,'QA-FISCAL',['total_amount'=>300,'created_by'=>$owner->id]);
        $history=$this->history($company,$date,$date,'combined','all',$owner);
        $method=new \ReflectionMethod(PosController::class,'financialHistoryCsv');
        $response=$method->invoke(new PosController(),$history,true);
        ob_start();$response->sendContent();$csv=ob_get_clean();
        $this->assertStringContainsString('net,450',$csv);
        $this->assertStringContainsString('Business-day reconciliation',$csv);
        $tab=new \ReflectionMethod(PosController::class,'salesReportTab');
        $request=Request::create('/pos/reports','GET',['tab'=>'combined']);
        $this->assertSame('combined',$tab->invoke(new PosController(),$request,$owner));
        $owner->pos_role='pos_cashier';$owner->pos_billing_scope='local';
        $this->assertSame('local',$tab->invoke(new PosController(),$request,$owner));
        $owner->pos_billing_scope='pra';
        $this->assertSame('pra',$tab->invoke(new PosController(),$request,$owner));
        $view=view('pos.partials.financial-history',[
            'financialHistory'=>$history,'canExportsPlan'=>true,'canAnalyticsPlan'=>true,'tab'=>'combined','selectedCashier'=>'all',
            'user'=>$owner,'teamMembers'=>collect([$owner]),
        ])->render();
        $this->assertStringContainsString('450.00',$view);
        $this->assertStringContainsString('reconciliation=1',$view);
    }

}
