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

    public function test_owner_sees_check_lookup_failure_instead_of_no_green_pr_message(): void
    {
        Http::fake([
            'https://api.github.com/repos/jawadrao5555-alt/taxnest/pulls*' => Http::response([$this->eligiblePr()], 200),
            'https://api.github.com/repos/jawadrao5555-alt/taxnest/commits/*' => Http::response(['message' => 'private-upstream-detail'], 403, ['Retry-After' => '120']),
        ]);
        $owner = AdminUser::query()->create([
            'name' => 'Owner', 'email' => 'owner@example.test',
            'password' => 'secret-password', 'role' => 'super_admin',
        ]);
        $this->actingAs($owner, 'admin')->get('/admin/deployment-approval')
            ->assertOk()
            ->assertSee('GitHub deployment validation temporarily unavailable (check_runs, HTTP 403)')
            ->assertSee('Retry after 120 seconds')
            ->assertDontSee('private-upstream-detail')
            ->assertSee('PR availability could not be verified')
            ->assertDontSee('No eligible green cursor/* or replit/* PR is currently available.');
    }

    public function test_owner_password_approval_binds_exact_github_head_sha_in_one_step(): void
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
            'password' => 'secret-password',
        ]);

        $response->assertRedirect()->assertSessionHas('success');
        $approval = OwnerDeploymentApprovalRequest::query()->sole();
        $this->assertSame(self::SHA, $approval->head_sha);
        $this->assertSame('approved', $approval->status);
        $this->assertSame($owner->id, $approval->approved_admin_id);
        Mail::assertNothingSent();
        Mail::assertNotSent(DeploymentApprovalReady::class);
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
            'password' => 'secret-password',
        ])->assertSessionHasErrors('pull_request_number');

        $this->assertDatabaseCount('owner_deployment_approval_requests', 0);
    }

    public function test_replit_branch_is_eligible_when_all_other_release_bindings_match(): void
    {
        Http::fake([
            'https://api.github.com/repos/jawadrao5555-alt/taxnest/pulls/55' => Http::response(
                array_replace_recursive($this->eligiblePr(), ['head' => ['ref' => 'replit/bootstrap-release']]),
                200
            ),
            'https://api.github.com/repos/jawadrao5555-alt/taxnest/commits/'.self::SHA.'/check-runs*' => Http::response([
                'check_runs' => [['name' => 'validate', 'status' => 'completed', 'conclusion' => 'success']],
            ], 200),
        ]);
        $owner = AdminUser::query()->create([
            'name' => 'Owner',
            'email' => 'replit-owner@example.test',
            'password' => 'secret-password',
            'role' => 'super_admin',
        ]);

        $this->actingAs($owner, 'admin')->post('/admin/deployment-approval', [
            'pull_request_number' => 55,
            'password' => 'secret-password',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('owner_deployment_approval_requests', [
            'pull_request_number' => 55,
            'head_sha' => self::SHA,
            'status' => 'approved',
        ]);
    }

    public function test_password_is_required_and_wrong_password_does_not_create_a_request(): void
    {
        Http::fake();
        $owner = AdminUser::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'secret-password',
            'role' => 'super_admin',
        ]);

        $this->actingAs($owner, 'admin')->post('/admin/deployment-approval', [
            'pull_request_number' => 55,
        ])->assertSessionHasErrors('password');

        $this->actingAs($owner, 'admin')->post('/admin/deployment-approval', [
            'pull_request_number' => 55,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('owner_deployment_approval_requests', 0);
    }

    public function test_password_rotation_revokes_cached_owner_approval_password_immediately(): void
    {
        Mail::fake();
        Http::fake([
            'https://api.github.com/repos/jawadrao5555-alt/taxnest/pulls/55' => Http::response($this->eligiblePr(), 200),
            'https://api.github.com/repos/jawadrao5555-alt/taxnest/commits/'.self::SHA.'/check-runs*' => Http::response([
                'check_runs' => [['name' => 'validate', 'status' => 'completed', 'conclusion' => 'success']],
            ], 200),
        ]);
        $owner = AdminUser::query()->create([
            'name' => 'Cached owner',
            'email' => 'cached-owner@example.test',
            'password' => 'old-secret-password',
            'role' => 'super_admin',
        ]);
        $this->actingAs($owner, 'admin');
        AdminUser::query()->whereKey($owner->id)->update(['password' => bcrypt('new-secret-password')]);

        $this->post('/admin/deployment-approval', [
            'pull_request_number' => 55,
            'password' => 'old-secret-password',
        ])->assertSessionHasErrors('password');
        $this->assertDatabaseCount('owner_deployment_approval_requests', 0);

        $this->post('/admin/deployment-approval', [
            'pull_request_number' => 55,
            'password' => 'new-secret-password',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('owner_deployment_approval_requests', [
            'pull_request_number' => 55,
            'status' => 'approved',
            'approved_admin_id' => $owner->id,
        ]);
        Mail::assertNothingSent();
    }

    public function test_release_history_shows_direct_merge_and_requires_password_to_approve_its_exact_commit(): void
    {
        Mail::fake();
        $merge = str_repeat('b', 40);
        $pr = array_replace($this->eligiblePr(), [
            'state' => 'closed', 'merged' => true, 'merged_at' => '2026-11-06T14:11:07Z',
            'merge_commit_sha' => $merge,
        ]);
        $base = 'https://api.github.com/repos/jawadrao5555-alt/taxnest/';
        Http::preventStrayRequests();
        Http::fake([
            $base.'pulls?state=all*' => Http::response([$pr]),
            $base.'pulls/55' => Http::response($pr),
            $base.'commits/main' => Http::response(['sha' => $merge]),
            $base.'commits/'.self::SHA.'/check-runs*' => Http::response([
                'check_runs' => [['name' => 'validate', 'status' => 'completed', 'conclusion' => 'success']],
            ]),
        ]);
        $owner = AdminUser::query()->create([
            'name' => 'Owner', 'email' => 'owner@example.test',
            'password' => 'secret-password', 'role' => 'super_admin',
        ]);

        $this->actingAs($owner, 'admin')->get('/admin/deployment-approval?history=1')
            ->assertOk()->assertSee($merge)->assertSee('No deployment record')
            ->assertSee('Unverified')->assertSee('Approve deployment of this commit');
        $this->assertDatabaseCount('owner_deployment_approval_requests', 0);

        $form = ['pull_request_number' => 55, 'merge_sha' => $merge];
        $this->post('/admin/deployment-approval', $form + ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
        $this->assertDatabaseCount('owner_deployment_approval_requests', 0);

        $this->post('/admin/deployment-approval', array_replace($form, [
            'merge_sha' => str_repeat('c', 40), 'password' => 'secret-password',
        ]))->assertSessionHasErrors('pull_request_number');
        $this->assertDatabaseCount('owner_deployment_approval_requests', 0);

        $this->post('/admin/deployment-approval', $form + ['password' => 'secret-password'])
            ->assertSessionHas('success');
        $row = OwnerDeploymentApprovalRequest::query()->sole();
        $this->assertSame(self::SHA, $row->head_sha);
        $this->assertSame($merge, $row->merge_sha);
        $this->assertSame('approved', $row->status);
        $this->assertSame($owner->id, $row->approved_admin_id);

        $this->post('/admin/deployment-approval', $form + ['password' => 'secret-password'])
            ->assertSessionHasErrors('pull_request_number');
        $this->assertDatabaseCount('owner_deployment_approval_requests', 1);

        $row->update([
            'status' => 'succeeded', 'deploy_result' => 'success',
            'deployed_sha' => $merge, 'deployment_run_id' => 123,
        ]);
        $this->get('/admin/deployment-approval?history=1')->assertOk()
            ->assertSee('Passed for this commit')->assertSee('actions/runs/123')
            ->assertDontSee('Approve deployment of this commit');
        $this->get('/admin/deployment-approval?history=1&deployment=verified')->assertOk()
            ->assertSee('1 PR found')->assertSee('Passed for this commit');
        $row->update(['deployed_sha' => str_repeat('c', 40)]);
        $this->get('/admin/deployment-approval?history=1&deployment=verified')->assertOk()
            ->assertSee('No PRs match these filters.');
    }

    public function test_pr_history_searches_every_github_page_before_filtering_and_paginating(): void
    {
        $base = 'https://api.github.com/repos/jawadrao5555-alt/taxnest/';
        $listing = $base.'pulls?state=all&sort=created&direction=asc&per_page=100&page=';
        $pr = fn (int $number, array $fields = []) => array_replace($this->eligiblePr(), [
            'number' => $number,
            'title' => 'Synthetic open PR '.$number,
            'updated_at' => '2026-11-06T19:02:00Z',
        ], $fields);
        $first = array_map($pr, range(1, 100));
        $first[2] = $pr(3, [
            'title' => 'PRA kitchen printing fallback',
            'body' => "## Summary\nRepairs the kitchen queue. Fixes #502.\n<script>fixtureUnsafe()</script>",
            'state' => 'closed',
        ]);
        $second = array_map($pr, range(101, 130));
        $second[29] = $pr(130, [
            'title' => 'DI packing follow-up',
            'draft' => true,
            'body' => 'Tracks https://github.com/jawadrao5555-alt/taxnest/issues/502',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            $listing.'1' => Http::response($first),
            $listing.'2' => Http::response($second),
        ]);
        $owner = AdminUser::query()->create([
            'name' => 'Owner', 'email' => 'history-owner@example.test',
            'password' => 'secret-password', 'role' => 'super_admin',
        ]);
        $history = '/admin/deployment-approval?history=1';
        $this->actingAs($owner, 'admin')->get($history)
            ->assertOk()->assertSee('130 PRs found')->assertSee('Page 1 of 6')
            ->assertSee('DI packing follow-up')->assertDontSee('PRA kitchen printing fallback');
        $this->get($history.'&page=6')->assertOk()->assertSee('Page 6 of 6')
            ->assertSee('PRA kitchen printing fallback')->assertDontSee('DI packing follow-up');
        $this->get($history.'&q=issue+%23502')->assertOk()->assertSee('2 PRs found')
            ->assertSee('Repairs the kitchen queue.')->assertSee('DI packing follow-up')
            ->assertDontSee('<script>fixtureUnsafe()</script>', false);
        $this->get($history.'&q=502&state=closed&module=pra&from=2026-11-07&to=2026-11-07&deployment=missing')
            ->assertOk()->assertSee('1 PR found')->assertSee('PRA kitchen printing fallback')
            ->assertSee('Closed without merge')->assertDontSee('DI packing follow-up');
        $this->get($history.'&q=130&state=draft&module=di')->assertOk()
            ->assertSee('1 PR found')->assertSee('DI packing follow-up')->assertSee('Draft');
        $this->get($history.'&q=KITCHEN+QUEUE&from=2026-11-08')->assertOk()->assertSee('No PRs match these filters.');
        $this->get($history.'&q=KITCHEN+QUEUE')->assertOk()->assertSee('1 PR found');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('owner_deployment_approval_requests', 0);
    }

    public function test_pr_history_reports_failure_instead_of_showing_a_partial_repository(): void
    {
        $listing = 'https://api.github.com/repos/jawadrao5555-alt/taxnest/pulls?state=all&sort=created&direction=asc&per_page=100&page=';
        Http::preventStrayRequests();
        Http::fake([
            $listing.'1' => Http::response(array_map(fn ($number) => array_replace($this->eligiblePr(), [
                'number' => $number,
                'title' => 'Partial snapshot PR '.$number,
            ]), range(1, 100))),
            $listing.'2' => Http::response(['message' => 'upstream sensitive detail'], 429, ['Retry-After' => '30']),
        ]);
        $owner = AdminUser::query()->create([
            'name' => 'Owner', 'email' => 'history-owner@example.test',
            'password' => 'secret-password', 'role' => 'super_admin',
        ]);
        $this->actingAs($owner, 'admin')->get('/admin/deployment-approval?history=1')->assertOk()
            ->assertSee('GitHub deployment validation temporarily unavailable')
            ->assertDontSee('upstream sensitive detail')
            ->assertDontSee('Partial snapshot PR 100')
            ->assertDontSee('No PRs match these filters.');
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
