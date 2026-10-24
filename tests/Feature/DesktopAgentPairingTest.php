<?php
namespace Tests\Feature;

use App\Models\{Company,User};
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class DesktopAgentPairingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function(Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type');
            $t->boolean('is_internal_account')->default(false);
            $t->string('agent_api_key')->nullable(); $t->string('agent_api_key_hash')->nullable();
            $t->boolean('agent_enabled')->default(false); $t->boolean('agent_submits_pra')->nullable();
            $t->string('fbr_connection_mode')->default('cloud'); $t->boolean('fbr_reporting_enabled')->default(false);
            $t->text('pos_printer_settings')->nullable(); $t->text('feature_flags')->nullable();
            $t->softDeletes(); $t->timestamps();
        });
        Schema::create('users', function(Blueprint $t) {
            $t->id();$t->string('name');$t->unsignedBigInteger('company_id');$t->string('role');
            $t->string('pos_role');$t->text('pos_custom_access')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();
        });
        Schema::create('subscriptions', function(Blueprint $t) {
            $t->id();$t->unsignedBigInteger('company_id');$t->boolean('active')->default(true);
            $t->unsignedBigInteger('pricing_plan_id')->nullable();$t->timestamps();
        });
        // Controller security is tested directly through the actual declared HTTP
        // routes; unrelated panel layout/branch bootstrap is covered by existing tests.
        $this->withoutMiddleware([\App\Http\Middleware\PosAuth::class,
            \App\Http\Middleware\FbrPosAuth::class, \App\Http\Middleware\CheckCompanyApproval::class]);
        PosFeatureService::flushGateCaches();
    }
    private function owner(string $panel='pos', int $id=41, bool $internal=true): User
    {
        DB::table('companies')->insert(['id'=>$id,'name'=>'Fictional '.$id,'product_type'=>$panel,
            'is_internal_account'=>$internal,'pos_printer_settings'=>'{"receipt_printer":"Existing"}']);
        DB::table('users')->insert(['id'=>$id,'name'=>'Owner '.$id,'company_id'=>$id,
            'role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>true]);
        return User::findOrFail($id);
    }
    private function path(string $panel='pos'): string {return ($panel==='pos'?'/pos':'/fbr-pos').'/desktop/agent-pairing';}
    public function test_new_pairing_requires_confirmation_once_and_preserves_both_panels_routing(): void
    {
        foreach(['pos','fbrpos'] as $i=>$panel) {
            $owner=$this->owner($panel,41+$i);$this->actingAs($owner,$panel);
            $preview=$this->getJson($this->path($panel))->assertOk()->assertJsonMissingPath('api_key');
            $this->assertStringContainsString('no-store',$preview->headers->get('Cache-Control'));
            $this->assertNull(Company::find($owner->company_id)->agent_api_key);
            $payload=['nonce'=>$preview->json('nonce'),'company_id'=>$owner->company_id];
            $confirmed=$this->postJson($this->path($panel),$payload)->assertOk()->assertJsonPath('company_id',$owner->company_id);
            $company=Company::find($owner->company_id);
            $this->assertSame(hash('sha256',$confirmed->json('api_key')),$company->agent_api_key_hash);
            $this->assertSame('cloud',$company->fbr_connection_mode);
            $this->assertFalse((bool)$company->fbr_reporting_enabled);
            $this->assertSame('{"receipt_printer":"Existing"}',$company->getRawOriginal('pos_printer_settings'));
            if($panel==='pos')$this->assertFalse((bool)$company->agent_submits_pra);
            else $this->assertNull($company->agent_submits_pra);
            $this->postJson($this->path($panel),$payload)->assertStatus(409);
        }
    }
    public function test_expired_confirmation_does_not_mint_a_key(): void
    {
        $owner=$this->owner();$this->actingAs($owner,'pos');$p=$this->getJson($this->path())->assertOk();
        $this->travel(6)->minutes();
        $this->postJson($this->path(),['nonce'=>$p->json('nonce'),'company_id'=>41])->assertStatus(409);
        $this->assertNull(Company::find(41)->agent_api_key);
    }
    public function test_wrong_company_and_changed_login_cannot_reuse_confirmation(): void
    {
        $a=$this->owner();$b=$this->owner('pos',42);$this->actingAs($a,'pos');
        $p=$this->getJson($this->path())->assertOk();
        $this->postJson($this->path(),['nonce'=>$p->json('nonce'),'company_id'=>42])->assertStatus(409);
        $p=$this->getJson($this->path())->assertOk();$this->actingAs($b,'pos');
        $this->postJson($this->path(),['nonce'=>$p->json('nonce'),'company_id'=>41])->assertStatus(409);
        $this->assertSame(0,Company::whereNotNull('agent_api_key')->count());
    }
    public function test_rotation_and_disable_invalidate_pending_confirmation(): void
    {
        $a=$this->owner();Company::find(41)->update(['agent_api_key'=>'fictional-existing-key','agent_enabled'=>true,'agent_submits_pra'=>true]);
        $this->actingAs($a,'pos');$p=$this->getJson($this->path())->assertOk();
        Company::find(41)->update(['agent_api_key'=>'fictional-rotated-key']);
        $this->postJson($this->path(),['nonce'=>$p->json('nonce'),'company_id'=>41])->assertStatus(409);
        $p=$this->getJson($this->path())->assertOk();Company::find(41)->update(['agent_enabled'=>false]);
        $this->postJson($this->path(),['nonce'=>$p->json('nonce'),'company_id'=>41])->assertStatus(409);
        $this->assertFalse((bool)Company::find(41)->agent_enabled);
        $this->assertTrue((bool)Company::find(41)->agent_submits_pra);
    }
    public function test_guest_cashier_inactive_and_wrong_guard_are_denied(): void
    {
        $this->getJson($this->path())->assertForbidden();
        $a=$this->owner();$a->forceFill(['role'=>'pos_user','pos_role'=>'pos_cashier'])->save();
        $this->actingAs($a,'pos')->getJson($this->path())->assertForbidden();
        $a->forceFill(['role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>false])->save();
        $this->actingAs($a,'pos')->getJson($this->path())->assertForbidden();
        $a->forceFill(['is_active'=>true])->save();$this->actingAs($a,'pos');
        $this->getJson($this->path('fbrpos'))->assertForbidden();
    }
    public function test_existing_key_is_retained_and_explicit_routing_is_not_changed(): void
    {
        $a=$this->owner();Company::find(41)->update(['agent_api_key'=>'fictional-original','agent_enabled'=>true,'agent_submits_pra'=>true]);
        $this->actingAs($a,'pos');$p=$this->getJson($this->path())->assertOk();
        $this->postJson($this->path(),['nonce'=>$p->json('nonce'),'company_id'=>41])->assertOk()->assertJsonPath('api_key','fictional-original');
        $this->assertTrue((bool)Company::find(41)->agent_submits_pra);
    }
    public function test_fresh_plan_locked_company_cannot_pair_but_existing_key_is_grandfathered(): void
    {
        $a=$this->owner('pos',41,false);$this->actingAs($a,'pos');
        $this->getJson($this->path())->assertForbidden();
        Company::find(41)->update(['agent_api_key'=>'fictional-grandfathered']);
        $p=$this->getJson($this->path())->assertOk();
        $this->postJson($this->path(),['nonce'=>$p->json('nonce'),'company_id'=>41])->assertOk()->assertJsonPath('api_key','fictional-grandfathered');
    }
    public function test_public_pra_and_fbr_downloads_share_the_same_verified_version_and_digest(): void
    {
        $pra=$this->owner();$fbr=$this->owner('fbrpos',42);
        Company::whereIn('id',[41,42])->update(['agent_api_key'=>'fictional-configured']);
        $hash=str_repeat('b',64);
        \Illuminate\Support\Facades\Cache::put('taxnest_agent_latest_release',[
            'tag'=>'v1.13.22','version'=>'1.13.22','available'=>true,
            'exe'=>['url'=>'https://github.com/fictional/release/releases/download/v1.13.22/setup.exe','sha256'=>$hash],
            'zip'=>['url'=>'https://github.com/fictional/release/releases/download/v1.13.22/windows.zip','sha256'=>$hash],
        ],600);
        $this->actingAs($pra,'pos');$this->actingAs($fbr,'fbrpos');
        foreach(['/download/agent','/pos/agent/download','/fbr-pos/agent/download'] as $path) {
            foreach(['exe','zip'] as $type) {
                $r=$this->get($path.'?type='.$type)->assertRedirect()
                    ->assertHeader('X-Agent-Version','1.13.22')->assertHeader('X-Agent-SHA256',$hash);
                $this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));
            }
        }
    }

    public function test_forged_confirmation_never_creates_credentials(): void
    {
        $a=$this->owner();$this->actingAs($a,'pos');$this->getJson($this->path())->assertOk();
        $this->postJson($this->path(),['nonce'=>str_repeat('x',64),'company_id'=>41])->assertStatus(409);
        $this->assertNull(Company::find(41)->agent_api_key);
    }
    public function test_manager_custom_access_restriction_is_preserved(): void
    {
        $a=$this->owner();$a->forceFill(['role'=>'pos_user','pos_role'=>'pos_manager','pos_custom_access'=>[]])->save();
        $this->actingAs($a,'pos')->getJson($this->path())->assertForbidden();
        $this->assertNull(Company::find(41)->agent_api_key);
    }

}
