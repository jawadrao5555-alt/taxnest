<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\SupportCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportCaseLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_are_tenant_bound_bidirectional_idempotent_and_leave_resolution_history_intact(): void
    {
        $actor = AdminUser::create(['name' => 'Synthetic link owner', 'email' => 'link-owner@example.invalid', 'password' => 'SyntheticOnly123!', 'role' => 'super_admin']);
        $companies = [];
        foreach ([1, 2] as $n) {
            $companies[] = Company::create(['name' => 'Synthetic evidence '.$n, 'product_type' => 'pos', 'ntn' => 'LINK'.$n]);
        }
        $cases = [];
        foreach ([[$companies[0], 801], [$companies[0], 802], [$companies[1], 803]] as [$company,$uid]) {
            $cases[] = SupportCase::create(['company_id' => $company->id, 'source_box' => 'inbox', 'source_uid' => $uid,
                'subject' => 'Synthetic evidence', 'status' => 'open', 'opened_at' => now(), 'assigned_admin_id' => $actor->id]);
        }
        [$first,$related,$foreign] = $cases;
        $before = DB::table('support_cases')->orderBy('id')->get()->toJson();
        $this->actingAs($actor, 'admin');
        $payload = ['related_case_id' => $related->id, 'kind' => 'duplicate', 'note' => 'Same fictional incident, separately retained message.'];
        $this->post('/admin/support-inbox/cases/'.$first->id.'/links', $payload)->assertRedirect();
        $this->post('/admin/support-inbox/cases/'.$related->id.'/links', array_replace($payload, ['related_case_id' => $first->id]))->assertRedirect();
        $this->assertSame(1, DB::table('support_case_links')->count());
        $this->assertSame(2, DB::table('support_case_events')->where('event_type', 'case_linked')->count());
        $this->get('/admin/support-cases/'.$related->id)->assertOk()->assertSee('Duplicate case #'.$first->id);
        $this->postJson('/admin/support-inbox/cases/'.$first->id.'/links', array_replace($payload, ['related_case_id' => $foreign->id]))->assertNotFound();
        $this->post('/admin/support-inbox/cases/'.$first->id.'/links', array_replace($payload, ['related_case_id' => $first->id]))->assertUnprocessable();
        $this->assertSame($before, DB::table('support_cases')->orderBy('id')->get()->toJson());
        DB::table('admin_users')->where('id', $actor->id)->update(['role' => 'viewer']);
        $this->post('/admin/support-inbox/cases/'.$first->id.'/links', $payload)->assertForbidden();
        $this->assertSame(1, DB::table('support_case_links')->count());
    }

    public function test_reviewed_company_purge_retains_case_history_and_original_attribution(): void
    {
        $actor = AdminUser::create(['name' => 'Synthetic purge reviewer', 'email' => 'purge-link@example.invalid', 'password' => 'SyntheticOnly123!', 'role' => 'super_admin']);
        $company = Company::create(['name' => 'Synthetic retained support company', 'product_type' => 'di', 'ntn' => 'CASEPURGE']);
        $case = SupportCase::create(['company_id' => $company->id, 'source_box' => 'inbox', 'source_uid' => 1801,
            'subject' => 'Synthetic retained case', 'status' => 'open', 'opened_at' => now(), 'assigned_admin_id' => $actor->id]);
        $related = SupportCase::create(['company_id' => $company->id, 'source_box' => 'inbox', 'source_uid' => 1802,
            'subject' => 'Synthetic retained duplicate', 'status' => 'open', 'opened_at' => now(), 'assigned_admin_id' => $actor->id]);
        $this->actingAs($actor, 'admin')->post('/admin/support-inbox/cases/'.$case->id.'/links', [
            'related_case_id' => $related->id, 'kind' => 'duplicate', 'note' => 'Retained evidence survives reviewed company purge.',
        ])->assertRedirect();
        $company->delete();
        $this->actingAs($actor, 'admin')->delete('/admin/bin/'.$company->id.'/destroy', ['confirm_name' => $company->name])->assertRedirect();
        $this->assertFalse(Company::withTrashed()->whereKey($company->id)->exists());
        $case->refresh();
        $this->assertNull($case->company_id);
        $this->assertSame((int) $company->id, (int) $case->company_id_snapshot);
        $this->get('/admin/support-cases/'.$case->id)->assertOk()->assertSee($company->name)->assertSee('Duplicate case #'.$related->id);
        $this->postJson('/admin/support-inbox/cases/'.$case->id.'/links', ['related_case_id' => $related->id, 'kind' => 'duplicate', 'note' => 'No new links after company purge.'])->assertNotFound();
    }
}
