<?php
namespace Tests\Feature;
use App\Models\{Company,User};
use App\Services\MadadgarCompanyReadTools;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema,Http};
use Illuminate\Http\Request;
class MadadgarExpandedReadTest extends MadadgarCompanyReadToolsTest
{
    private function context(string $product='pos'):array {
        $c=Company::create(['name'=>'Synthetic owner source','product_type'=>$product,'pos_type'=>'restaurant','is_internal_account'=>true,'feature_flags'=>['inventory'=>true,'recipes'=>true,'kitchen'=>true,'kot'=>true]]);
        $u=User::create(['company_id'=>$c->id,'name'=>'Owner','role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>true]);auth($product==='di'?'web':$product)->setUser($u);return [$c,$u];
    }
    private function read(string $q,Company $c,User $u):array{return MadadgarCompanyReadTools::answer($q,$c->product_type,$c,$u);}
    public function test_retail_closing_uses_single_frozen_snapshot_and_never_guesses_overlapping_totals():void {
        foreach(['pos','fbrpos'] as$p){[$c,$u]=$this->context($p);$t=$p==='pos'?'pos_day_close_reports':'fbr_day_close_reports';
            Schema::create($t,function(Blueprint $b){$b->id();$b->unsignedBigInteger('company_id');$b->date('report_date');$b->decimal('cash_amount',12,2);$b->decimal('counted_cash',12,2)->nullable();});
            DB::table($t)->insert(['company_id'=>$c->id,'report_date'=>'2026-11-15','cash_amount'=>123,'counted_cash'=>null]);
            $r=$this->read('today day close',$c,$u);$this->assertStringContainsString('Saved cash PKR: 123.00',$r['text']);$this->assertStringContainsString('Saved counted cash PKR: unknown',$r['text']);
            DB::table($t)->insert(['company_id'=>$c->id,'report_date'=>'2026-11-15','cash_amount'=>999]);
            $r=$this->read('today day close',$c,$u);$this->assertStringContainsString('Multiple saved closes',$r['text']);$this->assertStringNotContainsString('1,122',$r['text']);
            $branch=DB::table('branches')->insertGetId(['company_id'=>$c->id,'name'=>'A','is_active'=>true]);session(['active_branch_id'=>$branch]);
            $this->assertSame('unavailable',$this->read('today day close',$c,$u)['read_evidence']['outcome']);session()->forget('active_branch_id');
        }Http::assertNothingSent();
    }
    public function test_di_owner_web_scope_reads_locked_only_and_denies_forged_or_stale_membership():void {
        [$c,$u]=$this->context('di');
        Schema::create('invoices',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('status');$t->decimal('total_amount',12,2);$t->decimal('total_sales_tax',12,2)->nullable();$t->timestamps();});
        foreach([[$c->id,'locked',321],[$c->id,'draft',999],[$c->id+1,'locked',987654]] as[$cid,$status,$amount])DB::table('invoices')->insert(['company_id'=>$cid,'status'=>$status,'total_amount'=>$amount,'created_at'=>now(),'updated_at'=>now()]);
        $r=$this->read('today billing',$c,$u);$this->assertSame('company_read',$r['source']);$this->assertStringContainsString('321.00',$r['text']);$this->assertStringNotContainsString('987,654',$r['text']);$this->assertStringNotContainsString('999.00',$r['text']);$this->assertSame(['invoices'],$r['read_evidence']['sources']);
        $this->assertSame('unavailable',$this->read('today payments',$c,$u)['read_evidence']['outcome']);
        DB::table('users')->where('id',$u->id)->update(['role'=>'employee']);$this->assertSame('company_read_unavailable',$this->read('today billing',$c,$u)['scope_reason']);Http::assertNothingSent();
    }
    public function test_di_endpoint_denies_cashier_and_only_allows_fixed_questions():void {
        [$c,$u]=$this->context('di');$controller=new \App\Http\Controllers\DiMadadgarReadController;
        try{$controller->show(Request::create('/madadgar/di-read','POST',['question'=>'SELECT secret FROM users']));$this->fail('arbitrary query accepted');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('question',$e->errors());}
        DB::table('users')->where('id',$u->id)->update(['pos_role'=>'pos_cashier']);
        try{$controller->show(Request::create('/madadgar/di-read','GET'));$this->fail('cashier accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertDatabaseCount('madadgar_read_audits',0);
    }
    public function test_di_customer_entry_has_private_page_fixed_form_and_rejects_other_product():void {
        [$c,$u]=$this->context('di');$controller=new \App\Http\Controllers\DiMadadgarReadController;
        // This lean adapter fixture omits the full DI shell schema; browser acceptance
        // renders the real page with migrated fixtures. Verify its private response contract here.
        $controllerSource=file_get_contents(app_path('Http/Controllers/DiMadadgarReadController.php'));
        $this->assertStringContainsString("header('Cache-Control','private, no-store')",$controllerSource);
        $html=file_get_contents(resource_path('views/madadgar/di-read.blade.php'));
        $this->assertStringContainsString('name="question"',$html);
        $this->assertStringContainsString("{{ explode(\"\\n\", $"."answer['text'])[0] }}",$html);
        $this->assertStringContainsString('data-di-read-metrics',$html);
        $this->assertStringContainsString("{{ $"."row['money'] ? 'PKR ' : '' }}{{ $"."row['value'] }}",$html);
        $this->assertStringNotContainsString('<pre',$html);
        $this->assertStringNotContainsString('{!!',$html);
        $this->assertStringNotContainsString('x-html',$html);
        $route=app('router')->getRoutes()->getByName('di.madadgar.read');
        $this->assertContains('auth',$route->gatherMiddleware());$this->assertContains('company',$route->gatherMiddleware());
        $c->update(['product_type'=>'pos']);
        try{$controller->show(Request::create('/madadgar/di-read','GET'));$this->fail('wrong product permitted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
    }
    public function test_di_navigation_is_translated_and_uses_persisted_owner_membership():void {
        [$c,$u]=$this->context('di');
        foreach(['en','ur','rur'] as$locale){app()->setLocale($locale);$html=\Illuminate\Support\Facades\Blade::render('<x-di-madadgar-read-link />');
            $this->assertStringContainsString('data-di-read-link',$html);
            $this->assertStringContainsString(e(__('madadgar_di.link')),$html);
            foreach(['title','scope','report','billing','tax','payments','read','audit','evidence','caution'] as$key)$this->assertNotSame('madadgar_di.'.$key,__('madadgar_di.'.$key));
        }
        DB::table('users')->where('id',$u->id)->update(['pos_role'=>'pos_cashier']);
        $this->assertStringNotContainsString('data-di-read-link',\Illuminate\Support\Facades\Blade::render('<x-di-madadgar-read-link />'));
        DB::table('users')->where('id',$u->id)->update(['pos_role'=>'pos_admin','company_id'=>$c->id+1]);
        $this->assertStringNotContainsString('data-di-read-link',\Illuminate\Support\Facades\Blade::render('<x-di-madadgar-read-link />'));
        DB::table('users')->where('id',$u->id)->update(['company_id'=>$c->id,'is_active'=>false]);
        $this->assertStringNotContainsString('data-di-read-link',\Illuminate\Support\Facades\Blade::render('<x-di-madadgar-read-link />'));
    }
    public function test_recipe_cost_reuses_waste_arithmetic_and_capacity_never_falls_back_from_campus():void {
        [$c,$u]=$this->context();
        Schema::create('pos_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->boolean('is_active')->default(true);});
        Schema::create('ingredients',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->boolean('is_active')->default(true);$t->string('unit');$t->decimal('cost_per_unit',12,4)->nullable();$t->decimal('current_stock',12,4)->nullable();});
        Schema::create('product_recipes',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('product_id');$t->unsignedBigInteger('ingredient_id');$t->boolean('is_active')->default(true);$t->decimal('quantity_needed',12,4);$t->decimal('waste_percent',6,2);});
        Schema::create('ingredient_stocks',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('ingredient_id');$t->unsignedBigInteger('branch_id');$t->decimal('quantity',12,4)->nullable();});
        $p=DB::table('pos_products')->insertGetId(['company_id'=>$c->id]);$i=DB::table('ingredients')->insertGetId(['company_id'=>$c->id,'unit'=>'kg','cost_per_unit'=>10,'current_stock'=>11]);
        DB::table('product_recipes')->insert(['company_id'=>$c->id,'product_id'=>$p,'ingredient_id'=>$i,'quantity_needed'=>2,'waste_percent'=>10]);
        $r=$this->read('recipe cost and servings',$c,$u);$this->assertStringContainsString('estimated ingredient cost PKR: 22.00',$r['text']);$this->assertStringContainsString('recorded-stock servings: 5',$r['text']);
        $a=DB::table('branches')->insertGetId(['company_id'=>$c->id,'name'=>'A','is_active'=>true]);session(['active_branch_id'=>$a]);
        $r=$this->read('recipe cost and servings',$c,$u);$this->assertStringContainsString('recorded-stock servings: unknown',$r['text']);
        DB::table('ingredient_stocks')->insert(['company_id'=>$c->id,'ingredient_id'=>$i,'branch_id'=>$a,'quantity'=>4.4]);
        $this->assertStringContainsString('recorded-stock servings: 2',$this->read('recipe cost and servings',$c,$u)['text']);
        DB::table('ingredients')->where('id',$i)->update(['company_id'=>$c->id+1]);
        $r=$this->read('recipe cost and servings',$c,$u);$this->assertStringContainsString('estimated ingredient cost PKR: unknown',$r['text']);$this->assertStringContainsString('recorded-stock servings: unknown',$r['text']);
        DB::table('ingredients')->where('id',$i)->update(['company_id'=>$c->id]);session()->forget('active_branch_id');
        // The request does not expand into an unbounded catalogue or recipe graph.
        for($n=0;$n<25;$n++){
            $id=DB::table('pos_products')->insertGetId(['company_id'=>$c->id]);
            DB::table('product_recipes')->insert(['company_id'=>$c->id,'product_id'=>$id,'ingredient_id'=>$i,'quantity_needed'=>2,'waste_percent'=>10]);
        }
        $r=$this->read('recipe cost and servings',$c,$u);
        $this->assertSame(1,$r['read_evidence']['values']['has_more']);
        $this->assertArrayNotHasKey('product_'.$id.'_cost',$r['read_evidence']['values']);
        for($n=0;$n<500;$n++)DB::table('product_recipes')->insert(['company_id'=>$c->id,'product_id'=>$p,'ingredient_id'=>$i,'quantity_needed'=>2,'waste_percent'=>10]);
        $r=$this->read('recipe cost and servings',$c,$u);
        $this->assertNull($r['read_evidence']['values']['product_'.$p.'_cost']);
        $this->assertNull($r['read_evidence']['values']['product_'.$p.'_servings']);
        Http::assertNothingSent();
    }
}
