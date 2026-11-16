<?php
namespace Tests\Feature;
use App\Models\{AdminUser,Company};
use App\Services\ReviewedDeviceProof;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class ReviewedDeviceProofTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp(); Schema::dropAllTables();
        Schema::create('companies',function(Blueprint $t){$t->id();$t->string('name');$t->string('product_type')->nullable();$t->string('pos_type')->nullable();$t->string('business_category')->nullable();$t->softDeletes();$t->timestamps();});
        Schema::create('admin_users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email');$t->string('password');$t->string('role');$t->timestamps();});
        Schema::create('pos_agent_devices',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('device_uid');});
        DB::table('pos_agent_devices')->insert(['company_id'=>1,'device_uid'=>'DEVICE-A']);
        (require database_path('migrations/2026_11_16_130000_create_reviewed_device_proofs_table.php'))->up();
        DB::table('companies')->insert([['id'=>1,'name'=>'Synthetic'],['id'=>2,'name'=>'Other']]);
        $admin=AdminUser::create(['name'=>'Reviewer','email'=>'reviewer@example.test','password'=>'SyntheticOnly123!','role'=>'super_admin']);
        auth('admin')->setUser($admin);
    }
    private function data(): array {return ['device_uid'=>'DEVICE-A','pos_id'=>'123','environment'=>'sandbox','evidence_reference'=>'synthetic-device-report','evidence_sha256'=>str_repeat('a',64),'review_note'=>'Reviewed synthetic device binding evidence','independent_evidence_reviewed'=>true];}
    public function test_exact_review_and_append_only_revocation_preserve_history(): void {
        $this->assertNull(ReviewedDeviceProof::reviewedProof(1,'DEVICE-A','123','sandbox'));
        $id=ReviewedDeviceProof::approve(1,$this->data());
        $this->assertSame($id,(int)ReviewedDeviceProof::reviewedProof(1,'DEVICE-A','123','sandbox')->id);
        foreach([[2,'DEVICE-A','123','sandbox'],[1,'DEVICE-B','123','sandbox'],[1,'DEVICE-A','124','sandbox'],[1,'DEVICE-A','123','production'],[1,'device-a','123','sandbox']] as $scope) $this->assertNull(ReviewedDeviceProof::reviewedProof(...$scope));
        $original=(array)DB::table('reviewed_device_proofs')->find($id);
        $revoked=ReviewedDeviceProof::revoke(1,$id,'Synthetic review evidence withdrawn');
        $this->assertNull(ReviewedDeviceProof::reviewedProof(1,'DEVICE-A','123','sandbox'));
        $this->assertSame($original,(array)DB::table('reviewed_device_proofs')->find($id));
        $this->assertSame($id,(int)DB::table('reviewed_device_proofs')->find($revoked)->revokes_id);
        $new=ReviewedDeviceProof::approve(1,$this->data());
        $this->assertNotSame($id,$new);
        $this->assertSame($new,(int)ReviewedDeviceProof::reviewedProof(1,'DEVICE-A','123','sandbox')->id);
    }
    public function test_review_page_is_owner_only_and_exposes_no_reporting_mutation(): void {
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        request()->setRouteResolver(fn() => (new \Illuminate\Routing\Route('GET','admin/companies/1/device-proofs',fn()=>null))->name('saas.admin.device-proofs.index'));
        $controller=app(\App\Http\Controllers\SaasAdmin\ReviewedDeviceProofController::class);
        $response=$controller->index(1);
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        $this->assertSame('saas-admin.reviewed-device-proofs',$response->getOriginalContent()->name());
        $view=file_get_contents(resource_path('views/saas-admin/reviewed-device-proofs.blade.php'));
        $this->assertStringContainsString('independent_evidence_reviewed',$view);
        $this->assertStringContainsString('No reviewed proof means HOLD',$view);
        $this->assertStringNotContainsString('fbr_enabled',$view);
        DB::table('admin_users')->where('id',auth('admin')->id())->update(['role'=>'support']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $controller->index(1);
    }

    public function test_cached_superadmin_and_self_reported_payload_cannot_grant_proof(): void {
        $actor=auth('admin')->user(); DB::table('admin_users')->where('id',$actor->id)->update(['role'=>'admin']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        ReviewedDeviceProof::approve(1,$this->data()+['actor_id'=>$actor->id,'reviewer_role'=>'super_admin']);
    }
    public function test_nonregistered_device_and_school_scope_cannot_be_approved(): void {
        foreach ([['device_uid'=>'NOT-REGISTERED'],['device_uid'=>'device-a']] as $change) {
            try {ReviewedDeviceProof::approve(1,array_merge($this->data(),$change));$this->fail('Unregistered device approved');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        }
        DB::table('companies')->where('id',1)->update(['product_type'=>'fbrpos','pos_type'=>'education']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        ReviewedDeviceProof::approve(1,$this->data());
    }
    public function test_approval_requires_independent_evidence_acknowledgement(): void {
        $data=$this->data();unset($data['independent_evidence_reviewed']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);ReviewedDeviceProof::approve(1,$data);
    }
    public function test_foreign_proof_cannot_be_revoked_through_other_company(): void {
        $id=ReviewedDeviceProof::approve(1,$this->data());
        try {ReviewedDeviceProof::revoke(2,$id,'Synthetic wrong tenant request');$this->fail('Foreign proof revoked');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(404,$e->getStatusCode());}
        $this->assertSame($id,(int)ReviewedDeviceProof::reviewedProof(1,'DEVICE-A','123','sandbox')->id);
    }
}
