<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Agent;
use App\Models\Company;
use App\Models\PaymentProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributorAttributionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): AdminUser
    {
        return AdminUser::create(['name'=>'Root','email'=>'root@example.test','password'=>'password','role'=>'super_admin']);
    }

    public function test_only_dedicated_audited_attribution_action_can_change_a_company_distributor(): void
    {
        $admin = $this->superAdmin();
        $old = Agent::create(['name'=>'Old','email'=>'old-d@example.test','status'=>'active','is_active'=>true]);
        $new = Agent::create(['name'=>'New','email'=>'new-d@example.test','status'=>'active','is_active'=>true]);
        $company = Company::create(['name'=>'Shop','ntn'=>'ATTR-1','product_type'=>'di','agent_id'=>$old->id]);

        // The ordinary profile endpoint must ignore an injected attribution field.
        $this->actingAs($admin, 'admin')->put("/admin/companies/{$company->id}", [
            'name'=>'Shop renamed', 'agent_id'=>$new->id,
        ])->assertRedirect();
        $this->assertSame($old->id, (int) $company->fresh()->agent_id);

        $this->actingAs($admin, 'admin')->post("/admin/companies/{$company->id}/distributor-attribution", [
            'agent_id'=>$new->id, 'reason'=>'Corrected signed referral form',
        ])->assertRedirect();
        $this->assertSame($new->id, (int) $company->fresh()->agent_id);
        $audit = AdminAuditLog::where('target_type','Company')->where('target_id',$company->id)
            ->where('action','Distributor attribution corrected')->latest('id')->firstOrFail();
        $this->assertSame($old->id, (int) $audit->metadata['old_agent_id']);
        $this->assertSame($new->id, (int) $audit->metadata['new_agent_id']);
        $this->assertSame('Corrected signed referral form', $audit->metadata['reason']);

        PaymentProof::create([
            'company_id'=>$company->id,
            'amount'=>10000,
            'proof_path'=>'test-proof.jpg',
            'billing_cycle'=>'annual',
            'status'=>'verified',
            'verified_at'=>now(),
        ]);
        $this->actingAs($admin, 'admin')->post("/admin/companies/{$company->id}/distributor-attribution", [
            'agent_id'=>$old->id, 'reason'=>'too late',
        ])->assertStatus(422);
        $this->assertSame($new->id, (int) $company->fresh()->agent_id);

        $this->actingAs($admin, 'admin')->post("/admin/companies/{$company->id}/distributor-attribution", [
            'agent_id'=>$old->id, 'reason'=>'Court ordered correction', 'super_admin_override'=>true,
        ])->assertRedirect();
        $this->assertSame($old->id, (int) $company->fresh()->agent_id);
        $this->assertDatabaseHas('admin_audit_logs', [
            'target_type'=>'Company', 'target_id'=>$company->id,
            'action'=>'Distributor attribution super-admin override',
        ]);
    }
}