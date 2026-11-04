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
    public function test_receipt_never_shows_unaccepted_reference_and_is_tenant_scoped(): void
    {
        $this->enable(); $id=$this->bill(); $sale=PosTransaction::findOrFail($id);
        $r=$this->service->capture($this->company,$id,$this->owner->id,'sandbox');
        $r->update(['state'=>'verification_required','sandbox_invoice_number'=>'UNCONFIRMED']);
        $reader=app(PosFiscalReceipt::class);
        $this->assertNull($reader->federal($sale)['reference']);
        $r->update(['state'=>'accepted','sandbox_invoice_number'=>'TEST-SANDBOX-ACK']);
        $this->assertSame('TEST-SANDBOX-ACK',$reader->federal($sale)['reference']);
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
}
