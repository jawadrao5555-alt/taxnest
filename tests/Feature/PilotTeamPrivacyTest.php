<?php
namespace Tests\Feature;
use App\Http\Controllers\PilotTeamController;
use App\Models\{Company, User};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema, Crypt};
use Tests\TestCase;

class PilotTeamPrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); Schema::dropAllTables(); $this->withoutVite();
        view()->share('errors',new \Illuminate\Support\ViewErrorBag());
        Schema::create('companies',function(Blueprint $t){$t->id();$t->string('name');$t->string('product_type');$t->string('pos_type');$t->string('business_category');$t->boolean('is_internal_account')->default(true);$t->softDeletes();$t->timestamps();});
        Schema::create('users',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('name');$t->string('email');$t->string('username')->nullable();$t->string('password');$t->text('pos_team_password_enc')->nullable();$t->string('remember_token')->nullable();$t->unsignedBigInteger('default_branch_id')->nullable();$t->string('role');$t->string('pos_role')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('branches',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('name');});
    }
    private function login(string $category): User
    {
        $c=Company::withoutEvents(fn()=>Company::create(['name'=>'Synthetic pilot','product_type'=>'fbrpos','pos_type'=>$category,'business_category'=>$category,'is_internal_account'=>true]));
        $u=User::withoutEvents(fn()=>User::create(['company_id'=>$c->id,'name'=>'Owner','email'=>'owner'.$c->id.'@example.test','password'=>'HASH-OWNER-CANARY','role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>true]));
        $this->actingAs($u,'fbrpos');app()->instance('currentCompanyId',$c->id);return $u;
    }
    public function test_both_pilot_team_pages_never_load_or_render_existing_credentials(): void
    {
        foreach(['education','clinic'] as $category){
            $owner=$this->login($category);
            $cipher=Crypt::encryptString('SYNTHETIC-PLAINTEXT-CANARY');
            DB::table('users')->insert(['company_id'=>$owner->company_id,'name'=>'Safe member','email'=>'member'.$owner->company_id.'@example.test','username'=>'safeuser','role'=>'employee','pos_role'=>'pos_cashier','is_active'=>1,'password'=>'HASH-CANARY','pos_team_password_enc'=>$cipher,'remember_token'=>'TOKEN-CANARY']);
            DB::table('users')->insert(['company_id'=>999,'name'=>'FOREIGN-MEMBER-CANARY','email'=>'foreign'.$owner->company_id.'@example.test','role'=>'employee','pos_role'=>'pos_cashier','is_active'=>1,'password'=>'FOREIGN-HASH']);
            $response=app(PilotTeamController::class)->index();$html=$response->getContent();
            foreach(['SYNTHETIC-PLAINTEXT-CANARY','HASH-CANARY','HASH-OWNER-CANARY','TOKEN-CANARY',$cipher,'FOREIGN-MEMBER-CANARY','pos_counterpart_user_id','teamPasswords','show_password'] as $secret)$this->assertStringNotContainsString($secret,$html);
            $this->assertStringContainsString('Safe member',$html);
            $this->assertStringContainsString('data-pilot-team-member=', $html);
            $this->assertStringContainsString('type="submit"', $html);
            $this->assertStringContainsString("this.form.reset(); this.closest('details').open = false", $html);
            $this->assertStringContainsString('name="csrf-token"', $html);
            $this->assertStringContainsString('Madadgar Support', $html);
            $this->assertStringContainsString('/fbr-pos/madadgar', str_replace('\\/', '/', $html));
            $this->assertStringContainsString('type="password" name="password"',$html);
            $this->assertStringNotContainsString('type="text" name="password"',$html);
            $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
            foreach($response->original->getData()['team'] as $member){
                foreach(['password','pos_team_password_enc','remember_token'] as $column)$this->assertArrayNotHasKey($column,$member->getAttributes());
            }
        }
    }
    public function test_cashier_cannot_read_team_and_readonly_has_no_mutation_forms(): void
    {
        $owner=$this->login('education');session()->put('impersonation.readonly',true);
        $html=app(PilotTeamController::class)->index()->getContent();
        $this->assertStringNotContainsString('name="password"',$html);
        $owner->role='employee';$owner->pos_role='pos_cashier';$owner->saveQuietly();
        try{app(PilotTeamController::class)->index();$this->fail('cashier must be denied');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
    }
    public function test_retail_delegate_and_team_mutation_routes_remain_unchanged(): void
    {
        $source=file_get_contents(app_path('Http/Controllers/PilotTeamController.php'));
        $this->assertStringContainsString('return app(FbrPosController::class)->fbrTeam();',$source);
        $routes=app('router')->getRoutes();
        $this->assertSame(PilotTeamController::class.'@index',$routes->getByName('fbrpos.team')->getActionName());
        foreach(['fbrpos.team.store'=>'fbrStoreTeamMember','fbrpos.team.update'=>'fbrUpdateTeamMember','fbrpos.team.toggle'=>'fbrToggleTeamMember'] as $name=>$method)$this->assertSame(\App\Http\Controllers\FbrPosController::class.'@'.$method,$routes->getByName($name)->getActionName());
    }
}
