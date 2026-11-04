<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, PricingPlan, Subscription, User};
use App\Services\{HealthAccessService, HealthModuleService};
use App\Support\NestErps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthErpAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_registers_maternity_home_assigns_erp_package_and_controls_its_entitlements(): void
    {
        $admin = AdminUser::create(['name'=>'Demo SaaS owner','email'=>'central@example.test',
            'password'=>'unusable-demo-password', 'role'=>'super_admin']);
        $this->actingAs($admin, 'admin')->get('/admin/companies/create')->assertOk()->assertSee('Maternity');
        $this->post('/admin/companies', [
            'name'=>'Demo Maternity','owner_name'=>'Demo Owner','email'=>'maternity@example.test','ntn'=>'DEMO-ERP-344',
            'status'=>'approved','product_type'=>NestErps::PRODUCT_TYPE,'health_org_type'=>'maternity',
            'admin_email'=>'maternity.owner@example.test','admin_name'=>'Demo Owner','admin_password'=>'OnlyForDemo!344A',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $hospital = Company::where('ntn','DEMO-ERP-344')->firstOrFail();
        $this->get('/admin/companies/'.$hospital->id)->assertOk()->assertSee('Hospital operations');
        $owner = User::where('company_id', $hospital->id)->firstOrFail();
        $this->assertSame('maternity', $hospital->health_org_type);
        $this->assertSame(HealthAccessService::ROLE_OWNER, HealthAccessService::roleFor($owner));
        $this->get('/admin/plans')->assertOk()->assertSee('Included hospital modules');
        $planForm = ['name'=>'Demo restricted hospital plan','price'=>10000,'invoice_limit'=>1000,'product_type'=>'erps',
            'configure_health_modules'=>1,'health_modules'=>['ipd','accounts','maternity'],'erps_vertical'=>'health'];
        $this->post('/admin/plans', $planForm)->assertSessionHasNoErrors();
        $plan = PricingPlan::where('name', $planForm['name'])->firstOrFail();
        $this->assertSame(['ipd','accounts','maternity'], $plan->health_modules);
        $this->post('/admin/subscriptions/assign', ['company_id'=>$hospital->id,'pricing_plan_id'=>$plan->id,
            'billing_cycle'=>'annual'])->assertSessionHasNoErrors();
        $this->assertTrue(Subscription::where('company_id', $hospital->id)->where('pricing_plan_id', $plan->id)->where('active', true)->exists());
        $this->actingAs($owner,'health')->get('/health/ipd')->assertOk();
        $this->getJson('/health/pharmacy')->assertForbidden();
        $this->actingAs($admin,'admin')->put('/admin/plans/'.$plan->id, ['health_modules'=>[]] + $planForm)->assertSessionHasNoErrors();
        $this->assertSame([], $plan->fresh()->health_modules);
        $this->actingAs($owner,'health')->getJson('/health/ipd')->assertForbidden();
        $this->actingAs($admin,'admin')->post('/admin/companies/'.$hospital->id.'/suspend')->assertSessionHasNoErrors();
        $this->actingAs($owner,'health')->get('/health/patients')->assertRedirect('/health/login');
        $this->actingAs($admin,'admin')->post('/admin/companies/'.$hospital->id.'/activate')->assertSessionHasNoErrors();
        $this->actingAs($owner,'health')->get('/health/patients')->assertOk();
        $retail = Company::create(['name'=>'Demo POS','ntn'=>'DEMO-RETAIL-344','product_type'=>'pos','status'=>'approved']);
        $this->actingAs($admin,'admin')->postJson('/admin/companies/'.$retail->id.'/erp-entitlements',
            ['policy'=>'custom', 'modules'=>['opd']])->assertNotFound();
        $this->assertNull($retail->fresh()->health_entitlements);
    }
}
