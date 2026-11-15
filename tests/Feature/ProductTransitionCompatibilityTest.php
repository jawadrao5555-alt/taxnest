<?php
namespace Tests\Feature;

use App\Models\{AdminUser, Company, User, PricingPlan};
use App\Services\ProductTransitionCompatibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Tests\TestCase;

class ProductTransitionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'super_admin'): void
    {
        $this->actingAs(AdminUser::create(['name'=>'Synthetic admin','email'=>uniqid().'@example.invalid','password'=>'Synthetic123!','role'=>$role]), 'admin');
        Mail::fake();
    }
    private function source(): Company
    {
        $company = Company::create(['name'=>'Synthetic source','ntn'=>uniqid('SRC'),'product_type'=>'di','status'=>'approved','company_status'=>'active']);
        User::create(['name'=>'Synthetic owner','email'=>uniqid().'@example.invalid','password'=>'Synthetic123!','company_id'=>$company->id,'role'=>'company_admin','is_active'=>true]);
        return $company;
    }
    public function test_populated_school_cannot_be_relabelled_and_all_historical_values_remain(): void
    {
        $this->admin(); $company = $this->source(); $company->update(['product_type'=>'fbrpos','business_category'=>'education','pos_type'=>'education']);
        DB::table('school_fee_receipts')->insert(['company_id'=>$company->id,'collected_by'=>$company->users()->first()->id,'request_key'=>(string)\Illuminate\Support\Str::uuid(),'student_reference'=>'SYN-OLD','fee_head'=>'tuition','amount_paisa'=>5511,'collected_at'=>now()]);
        $before=(array)DB::table('school_fee_receipts')->first();
        $this->postJson('/admin/companies/'.$company->id.'/change-type',['product_type'=>'pos','target_category'=>'other_service','target_package'=>'trial'])->assertUnprocessable()->assertJsonValidationErrors('product_type');
        $this->assertSame('fbrpos',$company->fresh()->product_type);
        $this->assertSame($before,(array)DB::table('school_fee_receipts')->first()); Http::assertNothingSent();
    }
    public function test_clone_requires_explicit_compatible_target_and_preserves_source_access(): void
    {
        $this->admin(); $source=$this->source(); $before=$source->fresh()->getAttributes();
        $this->postJson('/admin/companies/'.$source->id.'/clone-product',['products'=>['fbrpos'],'target_categories'=>['fbrpos'=>'wholesale'],'target_packages'=>['fbrpos'=>'trial']])->assertUnprocessable()->assertJsonValidationErrors('target_category');
        $this->postJson('/admin/companies/'.$source->id.'/clone-product',['products'=>['fbrpos'],'target_categories'=>['fbrpos'=>'education']])->assertRedirect();
        $response=$this->post('/admin/companies/'.$source->id.'/clone-product',['products'=>['fbrpos'],'target_categories'=>['fbrpos'=>'education'],'target_packages'=>['fbrpos'=>'trial']]);
        $response->assertRedirect(); $response->assertSessionMissing('error');
        $clone=Company::where('id','!=',$source->id)->where('product_type','fbrpos')->firstOrFail();
        $this->assertSame('education',$clone->business_category); $this->assertFalse((bool)$clone->fbr_reporting_enabled);
        $this->assertSame(0,DB::table('school_fee_receipts')->where('company_id',$clone->id)->count());
        $this->assertSame($before,$source->fresh()->getAttributes());
        $this->assertSame(1,User::where('company_id',$clone->id)->count()); Http::assertNothingSent();
    }
    public function test_wrong_product_package_is_rejected_without_changes(): void
    {
        $this->admin(); $company=$this->source();
        PricingPlan::create(['name'=>'Synthetic DI Only','product_type'=>'di','price'=>100,'invoice_limit'=>10,'is_trial'=>false]);
        $this->postJson('/admin/companies/'.$company->id.'/change-type',['product_type'=>'fbrpos','target_category'=>'education','target_package'=>'Synthetic DI Only'])->assertUnprocessable()->assertJsonValidationErrors('target_package');
        $this->assertSame('di',$company->fresh()->product_type);
    }
    public function test_legacy_quick_actions_open_credential_free_review_before_mutation(): void
    {
        $this->admin(); $company=$this->source();
        $response=$this->post('/admin/companies/'.$company->id.'/change-type',['product_type'=>'fbrpos']);
        $response->assertRedirect(); $this->assertStringContainsString('/product-review', $response->headers->get('Location'));
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Review product, category and package')->assertSee('target_category',false)->assertDontSee('agent_api_key',false)->assertDontSee('access_code',false);
        $this->assertSame('di',$company->fresh()->product_type);
    }

    public function test_non_super_admin_cannot_switch_or_clone_category(): void
    {
        $this->admin('admin');$company=$this->source();
        $this->postJson('/admin/companies/'.$company->id.'/change-type',['product_type'=>'fbrpos','target_category'=>'education','target_package'=>'trial'])->assertForbidden();
        $this->postJson('/admin/companies/'.$company->id.'/clone-product',['products'=>['fbrpos'],'target_categories'=>['fbrpos'=>'education'],'target_packages'=>['fbrpos'=>'trial']])->assertForbidden();
    }
}
