<?php
namespace Tests\Feature\Reporting;

use App\Services\Reporting\{DiagnosticBillEvidence,DiagnosticSourceEvidence};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class DiRetainedStockEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); Schema::dropAllTables();
        config(['app.timezone'=>'UTC','reporting_connector.timezone'=>'UTC']);
        Schema::create('companies',function(Blueprint $t){$t->id();$t->string('name');$t->string('product_type');});
        Schema::create('branches',function(Blueprint $t){$t->id();$t->integer('company_id');});
        Schema::create('invoices',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('branch_id')->nullable();});
        Schema::create('invoice_items',function(Blueprint $t){$t->id();$t->integer('invoice_id');$t->json('inventory_snapshot')->nullable();});
        Schema::create('inventory_movements',function(Blueprint $t){$t->id();foreach(['company_id','product_id','reference_id'] as $c)$t->integer($c);$t->integer('branch_id')->nullable();$t->string('reference_type');$t->text('notes');});
        Schema::create('di_invoice_inventory_postings',function(Blueprint $t){$t->id();foreach(['company_id','invoice_id','invoice_item_id','movement_id','product_id'] as $c)$t->integer($c);$t->integer('branch_id')->nullable();$t->timestamp('created_at');});
        DB::table('companies')->insert([['id'=>1,'name'=>'Synthetic DI','product_type'=>'di'],['id'=>2,'name'=>'Other DI','product_type'=>'di']]);
        DB::table('branches')->insert([['id'=>10,'company_id'=>1],['id'=>20,'company_id'=>1],['id'=>30,'company_id'=>2]]);
        $this->posting(1,1,10);$this->posting(2,1,10);$this->posting(3,1,20);$this->posting(4,2,30);$this->posting(5,1,null);
    }
    private function posting(int $id,int $company,?int $branch): void
    {
        DB::table('invoices')->insert(['id'=>$id,'company_id'=>$company,'branch_id'=>$branch]);
        DB::table('invoice_items')->insert(['id'=>$id,'invoice_id'=>$id,'inventory_snapshot'=>json_encode(['version'=>1,'company_id'=>$company,'product_id'=>9,'branch_id'=>$branch])]);
        DB::table('inventory_movements')->insert(['id'=>$id+100,'company_id'=>$company,'product_id'=>9,'branch_id'=>$branch,'reference_type'=>'di_invoice_item','reference_id'=>$id,'notes'=>'PRIVATE-DO-NOT-EXPORT']);
        DB::table('di_invoice_inventory_postings')->insert(['id'=>$id,'company_id'=>$company,'invoice_id'=>$id,'invoice_item_id'=>$id,'movement_id'=>$id+100,'product_id'=>9,'branch_id'=>$branch,'created_at'=>'2026-11-15 10:00:00']);
    }
    private function source(array $extra=[]): array
    {
        return app(DiagnosticSourceEvidence::class)->read($extra+['company_name'=>'Synthetic DI','source'=>'di.stock_postings','date_from'=>'2026-11-15'],[1]);
    }
    private function bill(int $id=1): array
    {
        return app(DiagnosticBillEvidence::class)->read(['company_name'=>'Synthetic DI','bill_id'=>$id],[1])['sources']['stock'];
    }
    public function test_exact_retained_links_are_read_only_and_paginated_without_financial_or_private_fields(): void
    {
        $before=DB::table('di_invoice_inventory_postings')->get()->toJson();
        $r=$this->source(['branch_id'=>10,'limit'=>1]);
        $this->assertSame('PARTIAL',$r['status']);$this->assertSame([1],array_column($r['rows'],'id'));$this->assertTrue($r['pagination']['has_more']);
        $this->assertSame([2],array_column($this->source(['branch_id'=>10,'after_id'=>1,'limit'=>1])['rows'],'id'));
        $this->assertSame([['id'=>1,'movement_id'=>101]],$this->bill()['rows']);
        $this->assertStringNotContainsString('PRIVATE',json_encode($r));$this->assertFalse($r['coverage']['complete']);
        $this->assertSame($before,DB::table('di_invoice_inventory_postings')->get()->toJson());
    }
    public function test_null_branch_and_other_campus_retained_history_remain_separate(): void
    {
        $this->assertSame([3],array_column($this->source(['branch_id'=>20])['rows'],'id'));
        $this->assertSame([1,2,3,5],array_column($this->source()['rows'],'id'));
        $this->assertSame('PARTIAL',$this->bill(5)['status']);
    }
    public function test_forged_foreign_movement_is_explicit_inconsistency_not_a_foreign_id(): void
    {
        DB::table('di_invoice_inventory_postings')->where('id',1)->update(['movement_id'=>104]);
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$this->bill()['status']);
        $r=$this->source(['branch_id'=>10]);$this->assertSame('INCONSISTENT_RETAINED_LINKS',$r['status']);$this->assertSame([],$r['rows']);
        $this->assertSame('PARTIAL',$this->source(['branch_id'=>20])['status']);
    }
    public function test_forged_item_and_movement_namespace_are_rejected(): void
    {
        DB::table('invoice_items')->where('id',1)->update(['invoice_id'=>4]);
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$this->bill()['status']);
        DB::table('invoice_items')->where('id',1)->update(['invoice_id'=>1]);
        DB::table('inventory_movements')->where('id',101)->update(['reference_type'=>'pos_transaction']);
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$this->bill()['status']);
    }
    public function test_changed_branch_is_reported_as_mismatch_not_no_history(): void
    {
        DB::table('invoices')->where('id',1)->update(['branch_id'=>20]);
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$this->bill()['status']);
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$this->source(['branch_id'=>10])['status']);
    }
    public function test_missing_table_is_unavailable_and_missing_movement_is_inconsistent(): void
    {
        DB::table('inventory_movements')->where('id',101)->delete();
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$this->bill()['status']);
        Schema::drop('inventory_movements');
        $this->assertSame('UNAVAILABLE',$this->bill()['status']);$this->assertSame('UNAVAILABLE',$this->source()['status']);
    }
    public function test_foreign_company_and_branch_remain_denied(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->source(['branch_id'=>30]);
    }
    public function test_foreign_bill_cannot_be_selected_by_direct_id(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);$this->bill(4);
    }
    public function test_collector_never_labels_inconsistent_links_observed(): void
    {
        Schema::create('diagnostic_source_observations',function(Blueprint $t){
            $t->id();$t->integer('company_id');$t->string('source');$t->date('business_date');$t->string('run_id');
            $t->integer('registry_version');$t->timestamp('collected_at');$t->string('status');$t->integer('retained_rows')->nullable();
            $t->timestamp('first_source_at')->nullable();$t->timestamp('last_source_at')->nullable();
        });
        // Freeze an explicit UTC instant: now()->setDate() inherits PHP's timezone,
        // which can put this UTC business date in the future after Karachi midnight.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $service=app(\App\Services\Reporting\DiagnosticSourceCollection::class);
        $this->assertSame('OBSERVED',$service->capture(1,'di.stock_postings','2026-11-15',(string)\Illuminate\Support\Str::uuid()));
        DB::table('di_invoice_inventory_postings')->where('id',1)->update(['movement_id'=>104]);
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$service->capture(1,'di.stock_postings','2026-11-15',(string)\Illuminate\Support\Str::uuid()));
        $this->assertNull(DB::table('diagnostic_source_observations')->orderByDesc('id')->first()->retained_rows);
        Schema::drop('inventory_movements');
        $this->assertSame('UNAVAILABLE',$service->capture(1,'di.stock_postings','2026-11-15',(string)\Illuminate\Support\Str::uuid()));
        $this->travelBack();
    }

    public function test_matching_forged_posting_and_movement_cannot_override_frozen_item_product(): void
    {
        DB::table('di_invoice_inventory_postings')->where('id',1)->update(['product_id'=>999]);
        DB::table('inventory_movements')->where('id',101)->update(['product_id'=>999]);
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$this->bill()['status']);
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$this->source()['status']);
    }
    public function test_missing_or_foreign_frozen_identity_is_unknown_not_current_catalogue_inference(): void
    {
        DB::table('invoice_items')->where('id',1)->update(['inventory_snapshot'=>null]);
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$this->bill()['status']);
        DB::table('invoice_items')->where('id',1)->update(['inventory_snapshot'=>json_encode(['version'=>1,'company_id'=>2,'product_id'=>9,'branch_id'=>null])]);
        $this->assertSame('INCONSISTENT_RETAINED_LINKS',$this->bill()['status']);
    }

}
