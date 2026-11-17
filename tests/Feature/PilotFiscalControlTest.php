<?php
namespace Tests\Feature;
use App\Models\{AdminUser,AdminAuditLog,Company,User};
use App\Services\{PilotFiscalControl,FbrService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;
class PilotFiscalControlTest extends TestCase
{
    use RefreshDatabase;
    private function owner():AdminUser{$a=AdminUser::create(['name'=>'Synthetic owner','email'=>uniqid().'@example.test','password'=>'Synthetic-only-123','role'=>'super_admin']);$this->actingAs($a,'admin');return $a;}
    private function company(string $category='education'):Company{return Company::create(['name'=>'Synthetic pilot','ntn'=>uniqid(),'product_type'=>'fbrpos','pos_type'=>$category,'business_category'=>$category,'fbr_reporting_enabled'=>true]);}
    private function url(Company $c):string{return '/admin/companies/'.$c->id.'/pilot-fiscal-control';}
    public function test_incomplete_school_and_hospital_setup_stays_effectively_off():void
    {
        $this->owner();Http::preventStrayRequests();
        foreach(['education','hospital'] as $category){$c=$this->company($category);$before=DB::table('companies')->find($c->id);
            $this->getJson($this->url($c))->assertOk()->assertJsonPath('enabled',false)->assertJsonPath('status','setup_pending')->assertJsonPath('historical_submission',false);
            $this->get($this->url($c))->assertOk()->assertSee('Save fiscal route');
            $this->postJson($this->url($c),['enabled'=>true])->assertStatus(422)->assertJsonPath('enabled',false)->assertJsonPath('reason','ims_setup_incomplete');
            $after=(array)DB::table('companies')->find($c->id);$expected=(array)$before;unset($after['updated_at'],$expected['updated_at']);$expected['fbr_reporting_enabled']=0;$this->assertEquals($expected,$after);
            $this->assertFalse($c->fresh()->fbr_reporting_enabled);
            $result=app(FbrService::class)->submitPosDiPayload([],'synthetic-no-token','production',$c->id,true);
            $this->assertSame('blocked',$result['state']);
        }
        $this->assertSame(2,AdminAuditLog::where('action','Pilot fiscal ON rejected')->count());Http::assertNothingSent();
    }
    public function test_configured_school_and_hospital_can_enable_ims_for_new_receipts():void
    {
        $this->owner();
        $school=$this->company();$school->update(['fbr_reporting_enabled'=>false,'fbr_pos_enabled'=>true,'fbr_pos_id'=>'123456','fbr_connection_mode'=>'fiscal_device','agent_enabled'=>true]);
        $this->postJson($this->url($school),['route'=>'ims'])->assertOk()->assertJsonPath('enabled',true)->assertJsonPath('selected_route','ims');
        $this->assertTrue($school->fresh()->fbr_reporting_enabled);
        $hospital=$this->company('hospital');$hospital->update(['fbr_reporting_enabled'=>false,'fbr_pos_enabled'=>true,'fbr_pos_id'=>'654321','fbr_connection_mode'=>'fiscal_device','agent_enabled'=>true]);
        $this->postJson($this->url($hospital),['route'=>'ims'])->assertOk()->assertJsonPath('enabled',true)->assertJsonPath('selected_route','ims');
        $this->assertTrue($hospital->fresh()->fbr_reporting_enabled);
    }
    public function test_off_only_updates_selected_flag_and_preserves_other_tenant_and_history():void
    {
        $this->owner();$c=$this->company();$other=$this->company('hospital');$before=(array)DB::table('companies')->find($c->id);$foreign=DB::table('companies')->find($other->id);
        $bill=DB::table('fbr_pos_transactions')->insertGetId(['company_id'=>$c->id,'invoice_number'=>'SYN-OLD-ACK','transaction_type'=>'sale','status'=>'completed','fbr_invoice_number'=>'SYN-ACK','fbr_status'=>'success','created_at'=>now(),'updated_at'=>now()]);$history=DB::table('fbr_pos_transactions')->find($bill);
        $this->postJson($this->url($c),['enabled'=>false])->assertOk()->assertJsonPath('enabled',false);
        $after=(array)DB::table('companies')->find($c->id);unset($before['updated_at'],$after['updated_at']);$before['fbr_reporting_enabled']=0;$this->assertEquals($before,$after);
        $this->assertEquals($foreign,DB::table('companies')->find($other->id));$this->assertEquals($history,DB::table('fbr_pos_transactions')->find($bill));
        $this->assertSame(1,AdminAuditLog::where('action','Pilot fiscal OFF confirmed')->count());
    }
    public function test_persisted_superadmin_readonly_and_tenant_guards():void
    {
        $actor=$this->owner();$c=$this->company();DB::table('admin_users')->where('id',$actor->id)->update(['role'=>'admin']);
        $this->getJson($this->url($c))->assertForbidden();$this->postJson($this->url($c),['enabled'=>false])->assertForbidden();
        DB::table('admin_users')->where('id',$actor->id)->update(['role'=>'super_admin']);
        $panel=User::factory()->create(['company_id'=>$c->id,'role'=>'company_admin','is_active'=>true]);$this->actingAs($panel,'fbrpos');
        $this->withSession(['impersonation'=>['readonly'=>true,'guard'=>'fbrpos','admin_id'=>$actor->id,'company_id'=>$c->id]])->postJson($this->url($c),['enabled'=>false])->assertForbidden();session()->forget('impersonation');
        auth('admin')->logout();$u=User::factory()->create(['company_id'=>$c->id,'role'=>'company_admin']);$this->actingAs($u,'fbrpos');$this->postJson($this->url($c),['enabled'=>true])->assertRedirect('/admin/login');
        $this->assertSame(0,AdminAuditLog::count());
    }
    public function test_retail_excluded_and_audit_failure_rolls_back_off():void
    {
        $this->owner();$retail=$this->company('retail');$this->postJson($this->url($retail),['enabled'=>false])->assertNotFound();$this->assertTrue($retail->fresh()->fbr_reporting_enabled);
        $c=$this->company();AdminAuditLog::creating(fn()=>throw new \RuntimeException('synthetic audit failure'));
        try{PilotFiscalControl::update($c->id,false);$this->fail('Audit error swallowed');}catch(\RuntimeException $e){$this->assertSame('synthetic audit failure',$e->getMessage());}finally{AdminAuditLog::flushEventListeners();}
        $this->assertSame(1,(int)DB::table('companies')->where('id',$c->id)->value('fbr_reporting_enabled'));
    }
}
