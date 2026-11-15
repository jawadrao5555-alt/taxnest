<?php
namespace Tests\Feature;
use App\Http\Controllers\HospitalWorkspaceSettingsController;
use App\Models\{Company, User};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class HospitalWorkspaceSettingsTest extends TestCase
{
    private Company $company;
    private User $owner;
    protected function setUp(): void
    {
        parent::setUp(); Schema::dropAllTables(); $this->withoutVite();
        view()->share('errors',new \Illuminate\Support\ViewErrorBag());
        Schema::create('companies',function(Blueprint $t){$t->id();$t->string('name');$t->string('product_type');$t->string('pos_type');$t->string('business_category');$t->integer('branch_limit_override')->nullable();$t->softDeletes();$t->timestamps();});
        Schema::create('users',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('name');$t->string('email');$t->string('password');$t->string('role');$t->string('pos_role')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('hospital_services',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('code');$t->string('label');$t->string('service_head');$t->unsignedBigInteger('rate_paisa');$t->boolean('active');$t->timestamps();$t->unique(['company_id','code']);});
        Schema::create('branches',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('name');$t->string('city')->nullable();$t->string('address')->nullable();$t->boolean('is_active');$t->boolean('is_head_office')->default(false);$t->timestamps();});
        $this->company=Company::withoutEvents(fn()=>Company::create(['name'=>'Synthetic Hospital','product_type'=>'fbrpos','pos_type'=>'clinic','business_category'=>'clinic','branch_limit_override'=>1]));
        $this->owner=User::withoutEvents(fn()=>User::create(['company_id'=>$this->company->id,'name'=>'Owner','email'=>'owner@example.test','password'=>'synthetic','role'=>'company_admin','is_active'=>true]));
        $this->actingAs($this->owner,'fbrpos'); app()->instance('currentCompanyId',$this->company->id);
    }
    private function request(array $data): Request { $r=Request::create('/fbr-pos/hospital/settings','POST',$data);$r->setLaravelSession(app('session.store'));app()->instance('request',$r);return $r; }
    private function service(array $extra=[]): Request { return $this->request(array_replace(['label'=>'Synthetic consultation','service_head'=>'consultation','rate'=>'250.75','active'=>1],$extra)); }
    public function test_catalog_updates_are_tenant_scoped_and_do_not_leak_other_catalogues(): void
    {
        DB::table('hospital_services')->insert(['company_id'=>999,'code'=>'other','label'=>'Other canary','service_head'=>'lab','rate_paisa'=>100,'active'=>true]);
        $controller=app(HospitalWorkspaceSettingsController::class);$controller->saveService($this->service());
        $saved=DB::table('hospital_services')->where('company_id',$this->company->id)->first();
        $this->assertSame(25075,(int)$saved->rate_paisa);
        $controller->saveService($this->service(['code'=>$saved->code,'label'=>'Updated catalogue','active'=>0]));
        $this->assertSame(2,DB::table('hospital_services')->count());
        $this->assertFalse((bool)DB::table('hospital_services')->where('code',$saved->code)->value('active'));
        $this->assertSame('Other canary',DB::table('hospital_services')->where('code','other')->value('label'));
        $html=$controller->index()->getContent();$this->assertStringContainsString('Updated catalogue',$html);$this->assertStringNotContainsString('Other canary',$html);
        try{$controller->saveService($this->service(['code'=>'other']));$this->fail('foreign code');}catch(HttpExceptionInterface $e){$this->assertSame(404,$e->getStatusCode());}
    }
    public function test_cashier_and_readonly_cannot_modify_catalog_or_campuses(): void
    {
        foreach (['readonly','cashier'] as $mode) {
            session()->put('impersonation.readonly',$mode==='readonly');
            if($mode==='cashier'){$this->owner->role='employee';$this->owner->pos_role='pos_cashier';$this->owner->saveQuietly();}
            foreach(['saveService','addCampus'] as $method){try{app(HospitalWorkspaceSettingsController::class)->$method($this->service(['name'=>'Campus']));$this->fail('forbidden');}catch(HttpExceptionInterface $e){$this->assertSame(403,$e->getStatusCode());}}
        }
        $this->assertSame(0,DB::table('hospital_services')->count());$this->assertSame(0,DB::table('branches')->count());
    }
    public function test_campus_creation_preserves_existing_company_settings_and_enforces_quota(): void
    {
        $before=$this->company->fresh()->getAttributes();
        app(HospitalWorkspaceSettingsController::class)->addCampus($this->request(['name'=>'Campus A','city'=>'Synthetic']));
        $this->assertSame($before,$this->company->fresh()->getAttributes());
        $row=DB::table('branches')->first();$this->assertSame($this->company->id,(int)$row->company_id);$this->assertTrue((bool)$row->is_head_office);
        try{app(HospitalWorkspaceSettingsController::class)->addCampus($this->request(['name'=>'Campus B']));$this->fail('quota');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('name',$e->errors());}
        $this->assertSame(1,DB::table('branches')->count());
    }
}
