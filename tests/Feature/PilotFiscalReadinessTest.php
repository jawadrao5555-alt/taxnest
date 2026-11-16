<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class PilotFiscalReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_checklists_do_not_accept_self_attested_activation_or_credentials(): void
    {
        $admin = AdminUser::create(['name'=>'Synthetic owner','email'=>'readiness@example.test','password'=>'Synthetic-123','role'=>'super_admin']);
        $this->actingAs($admin, 'admin');
        Http::preventStrayRequests();
        foreach (['education'=>'school','hospital'=>'hospital'] as $category=>$label) {
            $company = Company::create(['name'=>'Synthetic '.$label,'ntn'=>'SYN-'.$label,'product_type'=>'fbrpos','pos_type'=>$category,'business_category'=>$category]);
            $url = '/admin/companies/'.$company->id.'/pilot-fiscal-control';
            $before = DB::table('companies')->find($company->id);
            $state = $this->getJson($url)->assertOk()->assertJsonPath('readiness.category',$label)
                ->assertJsonPath('readiness.integration_implemented',false)
                ->assertJsonPath('readiness.activation_available',false)
                ->assertJsonPath('readiness.sandbox_verified',false)
                ->assertJsonPath('readiness.historical_backfill_allowed',false)->json('readiness');
            $this->assertSame(['applicability','transport','tax_mapping','sandbox','acknowledgement','uncertain_recovery','implementation'],array_column($state['requirements'],'id'));
            $this->assertSame(['unverified','unverified','unverified','unverified','unverified','unverified','pending'],array_column($state['requirements'],'status'));
            $this->get($url)->assertOk()->assertSee(ucfirst($label).' verification checklist')->assertSee('ON unavailable');
            $this->postJson($url,['enabled'=>true,'readiness'=>['sandbox_verified'=>true,'activation_available'=>true],'route_verified'=>true,'sandbox_token'=>'synthetic-never-store'])
                ->assertStatus(422)->assertJsonPath('readiness',$state)->assertJsonPath('enabled',false);
            $this->assertEquals($before, DB::table('companies')->find($company->id));
            $this->assertStringNotContainsString('synthetic-never-store', DB::table('admin_audit_logs')->get()->toJson());
        }
        Http::assertNothingSent();
    }
}
