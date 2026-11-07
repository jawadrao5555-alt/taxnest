<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\SupportCase;
use App\Services\SupportMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportCaseLifecycleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_schema_retains_company_owner_resolution_and_reopen_evidence(): void
    {
        $admin = AdminUser::query()->create([
            'name' => 'Synthetic Support Owner',
            'email' => 'support-owner@example.test',
            'password' => 'SyntheticOnly123!',
            'role' => 'super_admin',
        ]);
        $company = Company::query()->create([
            'name' => 'Synthetic Support Tenant',
            'ntn' => 'SUPPORT1',
            'product_type' => 'pos',
            'email' => 'support-tenant@example.test',
            'status' => 'approved',
            'company_status' => 'active',
        ]);
        $this->mock(SupportMailService::class, function ($mail) {
            $mail->shouldReceive('getMessage')->once()->with('inbox', 901)->andReturn([
                'uid' => 901,
                'subject' => 'Synthetic support request',
                'message_id' => '<support-901@example.test>',
            ]);
        });

        $this->actingAs($admin, 'admin')
            ->post('/admin/support-inbox/inbox/901/case', ['company_id' => $company->id])
            ->assertRedirect('/admin/support-inbox/inbox/901');

        $case = SupportCase::query()->sole();
        $this->assertSame($company->id, $case->company_id);
        $this->assertSame($admin->id, $case->assigned_admin_id);

        $this->post("/admin/support-inbox/cases/{$case->id}/resolve", [
            'resolution_build' => 'synthetic.1',
            'resolution_note' => 'Synthetic exact-build acceptance passed.',
        ])->assertRedirect();
        $resolvedAt = $case->fresh()->resolved_at;

        $this->post("/admin/support-inbox/cases/{$case->id}/reopen", [
            'reopen_note' => 'Synthetic recurrence evidence received.',
        ])->assertRedirect();

        $case->refresh();
        $this->assertSame(SupportCase::STATUS_REOPENED, $case->status);
        $this->assertSame('synthetic.1', $case->resolution_build);
        $this->assertTrue($resolvedAt->equalTo($case->resolved_at));
        $this->assertCount(3, $case->events()->get());
        $this->assertSame(['opened', 'resolved', 'reopened'], $case->events()->pluck('event_type')->all());
    }
}
