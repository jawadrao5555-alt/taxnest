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
                ->assertJsonPath('readiness.integration_implemented',true)
                ->assertJsonPath('readiness.activation_available',false)
                ->assertJsonPath('readiness.sandbox_verified',false)
                ->assertJsonPath('readiness.historical_backfill_allowed',false)->json('readiness');
            $this->assertSame(['applicability','reportable_event','transport','authentication','tax_mapping','sandbox','acknowledgement','uncertain_recovery','implementation'],array_column($state['requirements'],'id'));
            $this->assertSame(['unverified','unverified','unverified','unverified','unverified','unverified','unverified','unverified',$label==='school'?'implemented':'pending'],array_column($state['requirements'],'status'));
            $this->assertSame($company->id, $state['company_id']);
            $this->assertFalse($state['evidence_transfer_allowed']);
            foreach ($state['requirements'] as $requirement) {
                $this->assertSame($company->id, $requirement['company_id']);
                $this->assertSame($label, $requirement['category']);
            }
            $this->get($url)->assertOk()->assertSee(ucfirst($label).' verification checklist')->assertSee('Save fiscal route');
            $this->postJson($url,['enabled'=>true,'readiness'=>['sandbox_verified'=>true,'activation_available'=>true],'route_verified'=>true,'sandbox_token'=>'synthetic-never-store'])
                ->assertStatus(422)->assertJsonPath('readiness',$state)->assertJsonPath('enabled',false);
            $this->assertEquals($before, DB::table('companies')->find($company->id));
            $this->assertStringNotContainsString('synthetic-never-store', DB::table('admin_audit_logs')->get()->toJson());
        }
        Http::assertNothingSent();
    }
    public function test_another_company_or_category_readiness_cannot_be_replayed_as_activation_evidence(): void
    {
        $admin = AdminUser::create(['name'=>'Synthetic owner','email'=>'binding@example.test','password'=>'Synthetic-123','role'=>'super_admin']);
        $this->actingAs($admin, 'admin');
        Http::preventStrayRequests();
        $companies = collect(['education', 'education', 'hospital'])->map(fn ($category, $index) =>
            Company::create(['name'=>'Synthetic binding '.$index,'ntn'=>'SYN-BIND-'.$index,'product_type'=>'fbrpos','pos_type'=>$category,'business_category'=>$category]));
        $before = DB::table('companies')->orderBy('id')->get()->toArray();
        $source = $this->getJson('/admin/companies/'.$companies[0]->id.'/pilot-fiscal-control')->assertOk()->json('readiness');
        $source['sandbox_verified'] = true;
        $source['activation_available'] = true;
        $source['integration_implemented'] = true;
        foreach ($source['requirements'] as &$requirement) $requirement['status'] = 'verified';
        unset($requirement);
        foreach ($companies->slice(1) as $target) {
            $url = '/admin/companies/'.$target->id.'/pilot-fiscal-control';
            $this->postJson($url, ['enabled'=>true,'company_id'=>$companies[0]->id,'readiness'=>$source])
                ->assertStatus(422)->assertJsonPath('enabled',false)
                ->assertJsonPath('readiness.company_id',$target->id)
                ->assertJsonPath('readiness.category',$target->isSchoolFeePilot()?'school':'hospital')
                ->assertJsonPath('readiness.activation_available',false)
                ->assertJsonPath('readiness.evidence_transfer_allowed',false);
        }
        $this->assertEquals($before, DB::table('companies')->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_four_category_route_candidates_are_independent_and_selector_fails_closed(): void
    {
        $admin = AdminUser::create(['name'=>'Synthetic owner','email'=>'routes@example.test','password'=>'Synthetic-123','role'=>'super_admin']);
        $this->actingAs($admin, 'admin');
        Http::preventStrayRequests();
        foreach (['education'=>'school','hospital'=>'hospital'] as $category=>$label) {
            $company = Company::create(['name'=>'Synthetic route '.$label,'ntn'=>'SYN-ROUTE-'.$label,'product_type'=>'fbrpos','pos_type'=>$category,'business_category'=>$category]);
            $url = '/admin/companies/'.$company->id.'/pilot-fiscal-control';
            $state = $this->getJson($url)->assertOk()->assertJsonPath('selected_route','off')->json();
            $this->assertSame(['ims','di'],array_column($state['readiness']['routes'],'route'));
            foreach ($state['readiness']['routes'] as $candidate) {
                $this->assertSame($company->id,$candidate['company_id']);
                $this->assertSame($label,$candidate['category']);
                $this->assertFalse($candidate['eligible']);
                $this->assertSame($candidate['route']==='ims',$candidate['adapter_implemented']);
                $this->assertFalse($candidate['sandbox_verified']);
                foreach ($candidate['requirements'] as $requirement) {
                    $this->assertSame($candidate['route'],$requirement['route']);
                    $this->assertSame($company->id,$requirement['company_id']);
                    $this->assertSame($label,$requirement['category']);
                }
                $this->postJson($url,['route'=>$candidate['route'],'verified'=>true,'sandbox_verified'=>true])
                    ->assertStatus(422)->assertJsonPath('selected_route','off')->assertJsonPath('readiness',$state['readiness']);
            }
            $before = DB::table('companies')->find($company->id);
            $this->postJson($url,['route'=>'ims','enabled'=>false])->assertStatus(422);
            $this->postJson($url,['route'=>'off','enabled'=>true])->assertStatus(422);
            $this->postJson($url,['route'=>'pra'])->assertStatus(422);
            $this->assertEquals($before,DB::table('companies')->find($company->id));
            $this->postJson($url,['route'=>'off'])->assertOk()->assertJsonPath('selected_route','off');
            $this->assertFalse($company->fresh()->fbr_reporting_enabled);
            $this->get($url)->assertOk()->assertSee('Reporting route')->assertSee('IMS — standalone FBR POS Desktop Agent')->assertSee('DI — future option');
        }
        Http::assertNothingSent();
    }

}
