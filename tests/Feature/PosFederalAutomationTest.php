<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosDiSandboxSubmission;
use App\Models\PosDiSandboxProfile;
use App\Models\User;
use App\Services\FbrService;
use App\Services\PraDiSandboxService;
use App\Support\PraDiSandboxGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use App\Models\{PosTransaction, PosFederalRule, PosFederalOutbox, PosImsProfile};
use App\Services\{PosFederalAutomation, PosFederalPayload, PosFiscalLineFacts, PosFiscalReceipt};

class PosFederalAutomationTest extends TestCase
{
    private Company $company;
    private User $owner;
    private PraDiSandboxService $service;
    private const TOKEN = 'fictional-sandbox-token-for-local-tests';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        User::flushScopeColumnCache();
        config(['pra_di_sandbox.enabled' => true, 'pra_di_sandbox.hosted_enabled' => false, 'pra_di_sandbox.production_enabled' => true]);
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type')->default('pos');
            $t->string('status')->default('approved'); $t->string('company_status')->default('active');
            $t->string('ntn')->default('1234567'); $t->string('province')->default('Punjab');
            $t->string('address')->default('Fictional test street'); $t->text('fbr_sandbox_token')->nullable();
            $t->text('fbr_production_token')->nullable(); $t->string('fbr_environment')->default('production');
            $t->string('fbr_sandbox_url')->nullable(); $t->string('fbr_connection_status')->nullable();
            $t->string('fbr_registration_no')->nullable(); $t->string('fbr_business_name')->nullable();
            $t->boolean('agent_enabled')->default(false);
            $t->softDeletes(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name')->default('Fictional Owner');
            $t->string('role')->default('company_admin'); $t->string('pos_role')->default('pos_admin');
            $t->boolean('is_active')->default(true); $t->string('email')->nullable();
            $t->string('password')->nullable(); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number'); $t->string('status')->default('completed');
            $t->string('invoice_mode')->default('pra'); $t->string('transaction_type')->default('sale');
            $t->unsignedBigInteger('parent_transaction_id')->nullable();
            $t->decimal('subtotal', 12, 2)->default(100); $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(16); $t->decimal('total_amount', 12, 2)->default(116);
            $t->string('payment_method'); $t->string('pra_status')->nullable(); $t->string('pra_invoice_number')->nullable();
            $t->boolean('is_archived')->default(false); $t->timestamps();
        });
        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('transaction_id'); $t->string('item_name');
            $t->decimal('quantity', 12, 3); $t->decimal('taxable_base', 12, 2)->nullable();
            $t->decimal('exempt_base', 12, 2)->nullable(); $t->decimal('tax_amount', 12, 2)->nullable();
            $t->timestamps();
        });
        foreach (['invoices', 'inventory_movements', 'customer_ledger'] as $name) {
            Schema::create($name, fn (Blueprint $t) => $t->id());
        }
        (require database_path('migrations/2026_11_02_120000_create_pos_di_sandbox_submissions_table.php'))->up();
        (require database_path('migrations/2026_11_03_100000_create_pos_di_sandbox_profiles_table.php'))->up();
        (require database_path('migrations/2026_11_03_120000_add_environments_to_pos_di_reporting.php'))->up();
        (require database_path('migrations/2026_11_03_150000_create_pos_fbr_integration_evidence.php'))->up();
        $id = DB::table('companies')->insertGetId(['name' => 'Fictional Sandbox Shop',
            'fbr_sandbox_token' => Crypt::encryptString(self::TOKEN),
            'fbr_production_token' => Crypt::encryptString('NEVER-USE-THIS-PRODUCTION-TOKEN'),
            'fbr_sandbox_url' => 'https://invalid.example/override']);
        $this->company = Company::findOrFail($id);
        $uid = DB::table('users')->insertGetId(['company_id' => $id]);
        $this->owner = User::findOrFail($uid);
        $this->actingAs($this->owner, 'pos');
        $this->service = app(PraDiSandboxService::class);
        (require database_path('migrations/2026_11_04_160000_create_pos_federal_automation.php'))->up();
        Http::preventStrayRequests();
    }

    private function bill(string $method = 'cash', array $override = []): int
    {
        $id = DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->company->id, 'invoice_number' => 'TEST-'.uniqid(),
            'payment_method' => $method, 'pra_status' => 'submitted', 'pra_invoice_number' => 'TEST-PRA-ONLY',
            'created_at' => now(), 'updated_at' => now(),
        ], $override));
        DB::table('pos_transaction_items')->insert(['transaction_id' => $id, 'item_name' => 'Fictional meal',
            'quantity' => 2, 'taxable_base' => 100, 'exempt_base' => 0, 'tax_amount' => 16]);
        return $id;
    }


    private function enable(): PosFederalRule
    {
        PosDiSandboxProfile::create(['company_id'=>$this->company->id,'enabled'=>true,'environment'=>'sandbox','updated_by'=>$this->owner->id]);
        return PosFederalRule::create(['company_id'=>$this->company->id,'channel'=>'di','environment'=>'sandbox',
            'enabled'=>true,'version'=>1,'mapping'=>['hs_code'=>'9801.2000','tax_rate'=>'0','uom'=>'Numbers, pieces, units',
            'sale_type'=>'Services','buyer_province'=>'Punjab','buyer_address'=>'Synthetic test address','scenario_id'=>'SN019'],
            'reviewed_by'=>$this->owner->id,'reviewed_at'=>now()]);
    }
    public function test_existing_company_defaults_off_and_provisional_bills_stay_local(): void
    {
        $service=app(PosFederalAutomation::class);
        $service->enqueue(PosTransaction::findOrFail($this->bill()));
        $this->assertSame(0,PosFederalOutbox::count());
        $this->enable();
        $service->enqueue(PosTransaction::findOrFail($this->bill(override:['pra_status'=>'local'])));
        $this->assertSame(0,PosFederalOutbox::count()); Http::assertNothingSent();
    }
    public function test_one_intent_freezes_mapping_and_does_not_mutate_sale_or_cross_tenants(): void
    {
        $rule=$this->enable(); $id=$this->bill(); $sale=PosTransaction::findOrFail($id);
        $before=(array)DB::table('pos_transactions')->find($id); $service=app(PosFederalAutomation::class);
        $service->enqueue($sale); $rule->update(['mapping'=>['tax_rate'=>'99'],'version'=>2]);
        $service->enqueue($sale);
        $this->assertSame(1,PosFederalOutbox::count());
        $this->assertSame('0',PosFederalOutbox::first()->rule_snapshot['mapping']['tax_rate']);
        $other=Company::create(['name'=>'Other fictional tenant','ntn'=>'7654321']);
        $service->enqueue(PosTransaction::findOrFail($this->bill(override:['company_id'=>$other->id])));
        $this->assertSame(1,PosFederalOutbox::count());
        $this->assertSame($before,(array)DB::table('pos_transactions')->find($id));
        Http::assertNothingSent();
    }
    public function test_channel_switch_blocks_queued_intent_instead_of_dispatching_elsewhere(): void
    {
        $this->enable(); $service=app(PosFederalAutomation::class); $service->enqueue(PosTransaction::findOrFail($this->bill()));
        PosDiSandboxProfile::query()->update(['enabled'=>false]);
        PosImsProfile::create(['company_id'=>$this->company->id,'enabled'=>true,'environment'=>'sandbox']);
        $row=PosFederalOutbox::first(); $service->process($row->id);
        $this->assertSame('blocked',$row->fresh()->state); Http::assertNothingSent();
    }
    public function test_existing_uncertain_record_is_linked_and_never_resent(): void
    {
        $this->enable(); $id=$this->bill();
        $r=$this->service->capture($this->company,$id,$this->owner->id,'sandbox');
        $r->update(['state'=>'verification_required']);
        $service=app(PosFederalAutomation::class); $service->enqueue(PosTransaction::findOrFail($id));
        $row=PosFederalOutbox::first(); $service->process($row->id); $service->process($row->id);
        $this->assertSame('linked_existing',$row->fresh()->state);
        $this->assertSame('verification_required',$r->fresh()->state); Http::assertNothingSent();
    }
    public function test_missing_legacy_facts_block_before_any_http(): void
    {
        $this->enable(); $id=$this->bill(); DB::table('pos_transaction_items')->where('transaction_id',$id)->update(['taxable_base'=>null]);
        $service=app(PosFederalAutomation::class); $service->enqueue(PosTransaction::findOrFail($id));
        $row=PosFederalOutbox::first(); $service->process($row->id);
        $this->assertSame('blocked',$row->fresh()->state); Http::assertNothingSent();
    }
    public function test_forward_facts_preserve_inclusive_exclusive_and_exempt_amounts(): void
    {
        $this->assertSame(['taxable_base'=>100.0,'exempt_base'=>0],PosFiscalLineFacts::fields(116,16,true,false));
        $this->assertSame(['taxable_base'=>100.0,'exempt_base'=>0],PosFiscalLineFacts::fields(100,16,false,false));
        $this->assertSame(['taxable_base'=>0,'exempt_base'=>100.0],PosFiscalLineFacts::fields(100,0,false,true));
    }
    public function test_payload_uses_reviewed_federal_rate_without_adding_pra_tax(): void
    {
        $this->enable(); $id=$this->bill(); $service=app(PosFederalAutomation::class);
        $service->enqueue(PosTransaction::findOrFail($id)); $row=PosFederalOutbox::first();
        $record=$this->service->capture($this->company,$id,$this->owner->id,'sandbox');
        $payload=app(PosFederalPayload::class)->build($row,$record,$this->service);
        $this->assertEquals(100,$payload['items'][0]['totalValues']);
        $this->assertEquals(0,$payload['items'][0]['salesTaxApplicable']);
        $this->assertEquals(116,PosTransaction::findOrFail($id)->total_amount);
        Http::assertNothingSent();
    }
    public function test_automatic_di_preserves_original_line_tax_when_no_flat_rate_is_set(): void
    {
        $rule = $this->enable();
        $rule->update(['mapping' => array_replace($rule->mapping, ['tax_source' => 'original_bill'])]);
        Schema::table('pos_transaction_items', fn (Blueprint $table) => $table->decimal('tax_rate', 8, 2)->nullable());
        $id = $this->bill('card', ['tax_amount' => 5, 'total_amount' => 105]);
        DB::table('pos_transaction_items')->where('transaction_id', $id)->update(['tax_rate' => 5, 'tax_amount' => 5]);
        $sale = PosTransaction::findOrFail($id);
        app(PosFederalAutomation::class)->enqueue($sale);
        $record = $this->service->capture($this->company, $id, $this->owner->id, 'sandbox');
        $payload = app(PosFederalPayload::class)->build(PosFederalOutbox::firstOrFail(), $record, $this->service);
        $this->assertSame('5%', $payload['items'][0]['rate']);
        $this->assertEquals(5, $payload['items'][0]['salesTaxApplicable']);
        $this->assertEquals(105, $payload['items'][0]['totalValues']);
        Http::assertNothingSent();
    }

    public function test_receipt_never_shows_unaccepted_reference_and_is_tenant_scoped(): void
    {
        $this->enable(); $id=$this->bill(); $sale=PosTransaction::findOrFail($id);
        $r=$this->service->capture($this->company,$id,$this->owner->id,'sandbox');
        $r->update(['state'=>'verification_required','sandbox_invoice_number'=>'UNCONFIRMED']);
        $reader=app(PosFiscalReceipt::class);
        $this->assertNull($reader->federal($sale)['reference']);
        $r->update(['state'=>'accepted','sandbox_invoice_number'=>'TEST-SANDBOX-ACK']);
        $this->assertSame('TEST-SANDBOX-ACK',$reader->federal($sale)['reference']);
        $live = $r->replicate();
        $live->fill(['environment'=>'production','state'=>'accepted','production_invoice_number'=>'TEST-LIVE-ACK'])->save();
        $this->assertSame('TEST-LIVE-ACK', $reader->federal($sale)['reference']);
        $live->update(['state'=>'verification_required']);
        $this->assertSame('PRODUCTION', $reader->federal($sale)['environment']);
        $this->assertNull($reader->federal($sale)['reference']);
        $sale->company_id=999; $this->assertNull($reader->federal($sale));
    }

    public function test_automatic_di_dispatches_once_and_keeps_original_money_single(): void
    {
        $this->enable(); PosDiSandboxProfile::query()->first()->update(['sandbox_token'=>'fictional-test-only']);
        Http::fake(['*'=>Http::response(['invoiceNumber'=>'TEST-AUTO-ACK','validationResponse'=>['statusCode'=>'00','status'=>'Valid','invoiceStatuses'=>[['itemSNo'=>'1','statusCode'=>'00','status'=>'Valid','invoiceNo'=>'TEST-AUTO-ACK-1']]]])]);
        $id=$this->bill(); $service=app(PosFederalAutomation::class); $sale=PosTransaction::findOrFail($id);
        $before=(array)DB::table('pos_transactions')->find($id); $service->enqueue($sale);
        $row=PosFederalOutbox::first(); $service->process($row->id); $service->process($row->id);
        $this->assertSame('dispatched',$row->fresh()->state);
        $this->assertSame('accepted',PosDiSandboxSubmission::first()->state);
        Http::assertSentCount(1); $this->assertSame($before,(array)DB::table('pos_transactions')->find($id));
        foreach (['invoices','inventory_movements','customer_ledger'] as $table) $this->assertSame(0,DB::table($table)->count());
    }
    public function test_automatic_local_ims_queues_for_existing_agent_without_cloud_post(): void
    {
        $rule=$this->enable(); PosDiSandboxProfile::query()->update(['enabled'=>false]);
        PosImsProfile::create(['company_id'=>$this->company->id,'enabled'=>true,'environment'=>'sandbox','connection_mode'=>'fiscal_device',
            'sandbox_pos_id'=>'12345','device_uid'=>'synthetic-device','local_ims_url'=>'http://127.0.0.1:8525/api/IMSFiscal/GetInvoiceNumberByModel']);
        $rule->update(['channel'=>'ims']); $service=app(PosFederalAutomation::class);
        $service->enqueue(PosTransaction::findOrFail($this->bill())); $row=PosFederalOutbox::first(); $service->process($row->id);
        $this->assertSame('dispatched',$row->fresh()->state);
        $this->assertSame('queued_agent',\App\Models\PosImsSubmission::first()->state); Http::assertNothingSent();
    }

    public function test_ims_without_catalogue_pct_uses_existing_pos_compatibility_default_once(): void
    {
        $rule=$this->enable(); PosDiSandboxProfile::query()->update(['enabled'=>false]);
        PosImsProfile::create(['company_id'=>$this->company->id,'enabled'=>true,'environment'=>'sandbox','connection_mode'=>'fiscal_device',
            'sandbox_pos_id'=>'12345','device_uid'=>'synthetic-device','local_ims_url'=>'http://127.0.0.1:8525/api/IMSFiscal/GetInvoiceNumberByModel']);
        $rule->delete();
        $data=['_token'=>'ims-code-test','channel'=>'ims','environment'=>'sandbox','enabled'=>1,
            'hs_code'=>'','tax_rate'=>'0','all_items_confirmed'=>1,'mapping_reviewed'=>1];
        $this->withSession(['_token'=>'ims-code-test'])->post('/pos/tax-integrations/automatic',$data)
            ->assertRedirect(route('pos.federal-auto'));
        $id=$this->bill(); $before=(array)DB::table('pos_transactions')->find($id);
        $service=app(PosFederalAutomation::class); $sale=PosTransaction::findOrFail($id);
        $service->enqueue($sale); $service->enqueue($sale);
        $row=PosFederalOutbox::firstOrFail(); $service->process($row->id); $service->process($row->id);
        $this->assertSame(1,PosFederalOutbox::count());
        $record=\App\Models\PosImsSubmission::firstOrFail();
        $this->assertSame('queued_agent',$record->state);
        $this->assertSame('00000000',$record->payload['Items'][0]['PCTCode']);
        $this->assertSame(0,(int)$record->payload['Items'][0]['TaxRate']);
        $this->assertSame(0,(int)$record->payload['TotalTaxCharged']);
        $this->assertSame('0',PosFederalRule::first()->mapping['tax_rate']);
        $this->assertSame($before,(array)DB::table('pos_transactions')->find($id));
        $this->assertSame(1,\App\Models\PosImsSubmission::count());
        $this->get('/pos/tax-integrations/automatic')->assertOk()
            ->assertSee('Reviewed PCT code (optional)')->assertSee('00000000');
        $version=PosFederalRule::first()->version;
        $this->postJson('/pos/tax-integrations/automatic',array_replace($data,['hs_code'=>'not-a-code']))
            ->assertUnprocessable()->assertJsonValidationErrors('hs_code');
        $this->assertSame($version,PosFederalRule::first()->version);
        Http::assertNothingSent();
    }

    private function storedOriginalBill(): int
    {
        Schema::table('pos_transactions',function(Blueprint $t){
            $t->boolean('tax_inclusive')->default(false);$t->decimal('tax_rate',8,2)->default(16);
        });
        Schema::table('pos_transaction_items',function(Blueprint $t){
            $t->string('item_type')->default('product');$t->unsignedBigInteger('item_id')->nullable();
            $t->text('deal_snapshot')->nullable();$t->decimal('unit_price',12,2)->default(460);
            $t->decimal('subtotal',12,2)->default(460);$t->decimal('tax_rate',8,2)->nullable()->default(16);
            $t->boolean('is_tax_exempt')->default(false);
        });
        $id=$this->bill(override:['subtotal'=>460,'tax_amount'=>74,'total_amount'=>534]);
        DB::table('pos_transaction_items')->where('transaction_id',$id)->update([
            'quantity'=>1,'tax_amount'=>73.6,'taxable_base'=>null,'exempt_base'=>null]);
        return $id;
    }

    public function test_blank_ims_rate_reports_original_stored_tax_and_recovery_never_rewrites_sale(): void
    {
        $rule=$this->enable();PosDiSandboxProfile::query()->update(['enabled'=>false]);$rule->delete();
        PosImsProfile::create(['company_id'=>$this->company->id,'enabled'=>true,'environment'=>'sandbox','connection_mode'=>'fiscal_device',
            'sandbox_pos_id'=>'12345','device_uid'=>'synthetic-device','local_ims_url'=>'http://127.0.0.1:8524/TcpService/IMSHttp']);
        $data=['_token'=>'original-tax-test','channel'=>'ims','environment'=>'sandbox','enabled'=>1,
            'hs_code'=>'','tax_rate'=>'','all_items_confirmed'=>1,'mapping_reviewed'=>1];
        $this->withSession(['_token'=>'original-tax-test'])->post('/pos/tax-integrations/automatic',$data)
            ->assertRedirect(route('pos.federal-auto'));
        $this->assertSame('original_bill',PosFederalRule::first()->mapping['tax_source']);
        $this->assertArrayNotHasKey('tax_rate',PosFederalRule::first()->mapping);
        $id=$this->storedOriginalBill();
        $before=(array)DB::table('pos_transactions')->find($id);$lineBefore=(array)DB::table('pos_transaction_items')->where('transaction_id',$id)->first();
        $service=app(PosFederalAutomation::class);$service->enqueue(PosTransaction::findOrFail($id));
        $row=PosFederalOutbox::sole();$service->process($row->id);$service->process($row->id);
        $record=\App\Models\PosImsSubmission::sole();
        $this->assertSame('dispatched',$row->fresh()->state);$this->assertSame('queued_agent',$record->state);
        $this->assertSame('stored_original_bill',$record->snapshot['recovery']['source']);
        $this->assertSame('original_pra_bill_projection',$record->snapshot['rounding_source']);
        $this->assertSame(16.0,(float)$record->payload['Items'][0]['TaxRate']);
        $this->assertSame(74.0,(float)$record->payload['TotalTaxCharged']);
        $this->assertSame(534.0,(float)$record->payload['TotalBillAmount']);
        $this->assertSame($before,(array)DB::table('pos_transactions')->find($id));
        $this->assertSame($lineBefore,(array)DB::table('pos_transaction_items')->where('transaction_id',$id)->first());
        $this->assertSame(1,\App\Models\PosImsSubmission::count());Http::assertNothingSent();
    }

    public function test_legacy_recovery_refuses_unknown_or_inconsistent_money_and_cross_tenants(): void
    {
        $id=$this->storedOriginalBill();$sale=PosTransaction::findOrFail($id);
        $this->assertNotNull(\App\Services\PosStoredFiscalRecovery::recover($sale));
        foreach (['discount_amount'=>1,'total_amount'=>600,'pra_status'=>'pending'] as $key=>$bad) {
            $copy=clone $sale;$copy->$key=$bad;
            $this->assertNull(\App\Services\PosStoredFiscalRecovery::recover($copy));
        }
        $other=new Company(['id'=>$this->company->id+1]);
        try {\App\Services\PosFiscalSnapshot::capture($other,$sale,true);$this->fail('Cross tenant recovery accepted');}
        catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        DB::table('pos_transaction_items')->where('transaction_id',$id)->update(['tax_rate'=>null]);
        $this->assertNull(\App\Services\PosStoredFiscalRecovery::recover($sale));
        $this->assertSame(0,\App\Models\PosImsSubmission::count());Http::assertNothingSent();
    }

    public function test_fee_migration_preserves_old_money_and_old_offline_packets(): void
    {
        $id=$this->bill();$before=(array)DB::table('pos_transactions')->find($id);
        Schema::create('pos_day_close_reports',function(Blueprint $t){$t->id();$t->decimal('total_amount',12,2);});
        DB::table('pos_day_close_reports')->insert(['total_amount'=>116]);
        $migration=require database_path('migrations/2026_11_05_080000_add_pra_ims_service_fee.php');
        $migration->up();$migration->up();
        $after=(array)DB::table('pos_transactions')->find($id);unset($after['fbr_service_charge']);
        $this->assertSame($before,$after);$this->assertEquals(0,DB::table('pos_transactions')->value('fbr_service_charge'));
        $this->assertEquals(116,DB::table('pos_day_close_reports')->value('total_amount'));
        $legacy=\Illuminate\Http\Request::create('/local-test','POST',['offline_queued_at'=>now()->toIso8601String()]);
        $this->assertSame(0.0,\App\Services\PosImsFee::forCheckout($legacy,$this->company->id,true));
        $legacy->merge(['fbr_service_charge'=>1]);
        $this->assertSame(1.0,\App\Services\PosImsFee::forCheckout($legacy,$this->company->id,false));
        $this->assertSame(0.0,\App\Services\PosImsFee::forCheckout($legacy,$this->company->id,true,[]));
        $this->assertSame(1.0,\App\Services\PosImsFee::forCheckout($legacy,$this->company->id,true,['fbr_service_charge'=>1]));
        try {\App\Services\PosImsFee::forCheckout($legacy,$this->company->id,true,['fbr_service_charge'=>2]);$this->fail('Invalid signed fee was silently changed');}
        catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $migration->down();$this->assertTrue(Schema::hasColumn('pos_transactions','fbr_service_charge'));
        Http::assertNothingSent();
    }

    public function test_original_ims_snapshot_retains_mixed_deal_component_tax_facts(): void
    {
        $id=$this->storedOriginalBill();
        $components=[];
        foreach([false,true] as $i=>$exempt) $components[]=['product_id'=>$i+1,'name'=>'Synthetic component '.$i,
            'qty'=>1,'allocation_version'=>1,'allocation_weight'=>1,'tax_facts'=>['is_tax_exempt'=>$exempt]];
        $components=\App\Services\PosDealAllocation::capture($components,100,0,16,false);
        DB::table('pos_transactions')->where('id',$id)->update(['subtotal'=>100,'tax_amount'=>8,'total_amount'=>108]);
        DB::table('pos_transaction_items')->where('transaction_id',$id)->update(['item_type'=>'deal','quantity'=>1,
            'subtotal'=>100,'unit_price'=>100,'tax_amount'=>8,'taxable_base'=>50,'exempt_base'=>50,'deal_snapshot'=>json_encode($components)]);
        $snapshot=\App\Services\PosFiscalSnapshot::capture($this->company,PosTransaction::findOrFail($id),true);
        $this->assertCount(2,$snapshot['lines']);
        $this->assertSame(8.0,$snapshot['lines'][0]['pra_tax']);$this->assertSame(0.0,$snapshot['lines'][1]['pra_tax']);
        $this->assertSame(16.0,$snapshot['lines'][0]['tax_rate']);$this->assertSame(0.0,$snapshot['lines'][1]['tax_rate']);
        Http::assertNothingSent();
    }

    public function test_di_still_rejects_missing_or_invalid_hs_code(): void
    {
        $this->enable();
        $this->get('/pos/tax-integrations/automatic')->assertOk()->assertSee('Reviewed HS code (required)')
            ->assertDontSee('existing FBR POS compatibility value');
        $data=['_token'=>'di-code-test','channel'=>'di','environment'=>'sandbox','enabled'=>1,'tax_rate'=>'0',
            'uom'=>'Numbers, pieces, units','sale_type'=>'Services','buyer_province'=>'Punjab',
            'buyer_address'=>'Synthetic locality','scenario_id'=>'SN019','all_items_confirmed'=>1,'mapping_reviewed'=>1];
        foreach (['','bad','00000000'] as $code) {
            $this->withSession(['_token'=>'di-code-test'])->postJson('/pos/tax-integrations/automatic',$data+['hs_code'=>$code])
                ->assertUnprocessable()->assertJsonValidationErrors('hs_code');
        }
        $this->assertSame('9801.2000',PosFederalRule::first()->mapping['hs_code']);
        Http::assertNothingSent();
    }

    public function test_finalization_event_enqueues_once_and_rollback_retains_no_intent(): void
    {
        $this->enable(); $sale=PosTransaction::findOrFail($this->bill(override:['status'=>'hold','pra_status'=>'local']));
        $sale->update(['status'=>'completed']); $this->assertSame(0,PosFederalOutbox::count());
        DB::beginTransaction(); $sale->update(['pra_status'=>'submitted']);
        $this->assertSame(1,PosFederalOutbox::count()); DB::rollBack();
        $this->assertSame(0,PosFederalOutbox::count());
        $sale->refresh()->update(['pra_status'=>'submitted']);
        $sale->update(['pra_invoice_number'=>'TEST-PRA-NEW']);
        $this->assertSame(1,PosFederalOutbox::count()); Http::assertNothingSent();
    }
    public function test_owner_mapping_requires_csrf_and_review_and_does_not_backfill(): void
    {
        $this->enable(); PosFederalRule::query()->delete(); $this->bill();
        $this->get('/pos/tax-integrations/automatic')->assertOk()->assertSee('Automatic reporting');
        $data=['channel'=>'di','environment'=>'sandbox','enabled'=>1,'hs_code'=>'9801.2000','tax_rate'=>'0',
            'uom'=>'Numbers, pieces, units','sale_type'=>'Services','buyer_province'=>'Punjab','buyer_address'=>'Synthetic locality','scenario_id'=>'SN019'];
        $this->postJson('/pos/tax-integrations/automatic',$data)->assertStatus(419);
        $data['_token']='test-auto-csrf';
        $this->withSession(['_token'=>'test-auto-csrf'])->postJson('/pos/tax-integrations/automatic',$data)->assertUnprocessable();
        $data+=['all_items_confirmed'=>1,'mapping_reviewed'=>1];
        $this->post('/pos/tax-integrations/automatic',$data)->assertRedirect(route('pos.federal-auto'));
        $this->assertTrue(PosFederalRule::first()->enabled); $this->assertSame(0,PosFederalOutbox::count());
        $this->owner->forceFill(['role'=>'employee','pos_role'=>'pos_cashier'])->saveQuietly();
        $this->get('/pos/tax-integrations/automatic')->assertForbidden(); Http::assertNothingSent();
    }

    public function test_automatic_bill_cannot_be_edited_or_deleted_while_federal_ack_is_pending(): void
    {
        $this->enable(); $id=$this->bill(override:['pra_status'=>null,'pra_invoice_number'=>null]);
        app(PosFederalAutomation::class)->enqueue(PosTransaction::findOrFail($id));
        $this->getJson('/pos/transaction/'.$id.'/edit')->assertStatus(409);
        $this->putJson('/pos/transaction/'.$id,[])->assertStatus(409);
        $this->deleteJson('/pos/transaction/'.$id)->assertStatus(409);
        $this->assertNotNull(DB::table('pos_transactions')->find($id)); Http::assertNothingSent();
    }

    public function test_print_sized_pra_and_fbr_qrs_decode_to_their_own_raw_reference(): void
    {
        if (!extension_loaded('gd')) $this->markTestSkipped('GD is required for PNG scan acceptance.');
        foreach (['202611040000123456789012345','SYN-FBR-ACK-20261104'] as $reference) {
            $uri=\App\Support\QrImage::dataUri($reference,5,4);
            $png=base64_decode(explode(',',$uri,2)[1]);
            foreach ([74,84] as $size) {
                $src=imagecreatefromstring($png); $dst=imagecreatetruecolor($size,$size);
                imagecopyresampled($dst,$src,0,0,0,0,$size,$size,imagesx($src),imagesy($src));
                ob_start(); imagepng($dst); $small=ob_get_clean();
                $this->assertSame($reference,(string)(new \chillerlan\QRCode\QRCode())->readFromBlob($small));
                imagedestroy($src); imagedestroy($dst);
            }
        }
    }
}
