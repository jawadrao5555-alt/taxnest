<?php

namespace Tests\Feature;

use App\Mail\DeploymentApprovalReady;
use App\Models\AdminUser;
use App\Models\OwnerDeploymentApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SmartDeploymentApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_owner_selects_pr_number_and_server_binds_exact_github_head_sha(): void
    {
        Mail::fake();
        Http::fake([
            'https://api.github.com/repos/jawadrao5555-alt/taxnest/pulls/55' => Http::response($this->eligiblePr(), 200),
            'https://api.github.com/repos/jawadrao5555-alt/taxnest/commits/'.self::SHA.'/check-runs*' => Http::response([
                'check_runs' => [['name' => 'validate', 'status' => 'completed', 'conclusion' => 'success']],
            ], 200),
        ]);

        $owner = AdminUser::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'secret-password',
            'role' => 'super_admin',
        ]);

        $response = $this->actingAs($owner, 'admin')->post('/admin/deployment-approval', [
            'pull_request_number' => 55,
            'head_sha' => str_repeat('b', 40),
        ]);

        $response->assertRedirect();
        $approval = OwnerDeploymentApprovalRequest::query()->sole();
        $this->assertSame(self::SHA, $approval->head_sha);
        $this->assertSame('pending', $approval->status);
        Mail::assertSent(DeploymentApprovalReady::class, fn ($mail) => $mail->hasTo('owner@example.test'));
    }

    public function test_signed_email_review_link_is_recipient_bound_and_does_not_approve(): void
    {
        $owner = AdminUser::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'secret-password',
            'role' => 'super_admin',
        ]);
        $other = AdminUser::query()->create([
            'name' => 'Other',
            'email' => 'other@example.test',
            'password' => 'secret-password',
            'role' => 'super_admin',
        ]);
        $approval = OwnerDeploymentApprovalRequest::query()->create([
            'pull_request_number' => 55,
            'head_sha' => self::SHA,
            'repository' => 'jawadrao5555-alt/taxnest',
            'status' => 'pending',
            'requested_admin_id' => $owner->id,
            'expires_at' => now()->addMinutes(30),
        ]);
        $url = URL::temporarySignedRoute(
            'saas.admin.deployment-approval.review',
            now()->addMinutes(30),
            ['requestId' => $approval->request_id, 'approver' => $owner->id]
        );

        $this->actingAs($other, 'admin')->get($url)->assertForbidden();
        $this->actingAs($owner, 'admin')->get($url)
            ->assertRedirect(route('saas.admin.deployment-approval', ['review' => $approval->request_id]));

        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertNull($approval->fresh()->approved_at);
    }

    public function test_ineligible_or_not_green_pr_cannot_create_request(): void
    {
        Http::fake([
            'https://api.github.com/repos/jawadrao5555-alt/taxnest/pulls/55' => Http::response(
                array_replace_recursive($this->eligiblePr(), ['head' => ['ref' => 'feature/not-allowed']]),
                200
            ),
        ]);
        $owner = AdminUser::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'secret-password',
            'role' => 'super_admin',
        ]);

        $this->actingAs($owner, 'admin')->post('/admin/deployment-approval', [
            'pull_request_number' => 55,
        ])->assertSessionHasErrors('pull_request_number');

        $this->assertDatabaseCount('owner_deployment_approval_requests', 0);
    }

    private function eligiblePr(): array
    {
        return [
            'number' => 55,
            'title' => 'Safe approval test',
            'state' => 'open',
            'draft' => false,
            'html_url' => 'https://github.com/jawadrao5555-alt/taxnest/pull/55',
            'base' => ['ref' => 'main', 'repo' => ['full_name' => 'jawadrao5555-alt/taxnest']],
            'head' => [
                'ref' => 'cursor/safe-approval-test-0f83',
                'sha' => self::SHA,
                'repo' => ['full_name' => 'jawadrao5555-alt/taxnest'],
            ],
        ];
    }
}
