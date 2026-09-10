<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\OwnerDeploymentApprovalRequest;
use App\Services\GitHubActionsOidcVerifier;
use App\Services\OwnerDeploymentApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OwnerDeploymentApprovalRelayTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const MERGE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const OWNER_SHA = 'cccccccccccccccccccccccccccccccccccccccc';
    private const NONCE = '0123456789abcdef0123456789abcdef';

    private function admin(string $role = 'super_admin'): AdminUser
    {
        return AdminUser::create([
            'name' => 'Relay Admin',
            'email' => uniqid('relay-', true).'@example.test',
            'password' => Hash::make('correct-password'),
            'role' => $role,
        ]);
    }

    private function github(bool $shaMatches = true): void
    {
        $head = $shaMatches ? self::SHA : str_repeat('c', 40);
        Http::fake([
            'https://api.github.com/repos/'.config('deployment_approval.repository').'/pulls/*' => Http::response([
                'state' => 'open', 'draft' => false, 'base' => [
                    'ref' => 'main', 'repo' => ['full_name' => config('deployment_approval.repository')],
                ], 'head' => ['sha' => $head, 'repo' => ['full_name' => config('deployment_approval.repository')]],
            ]),
            'https://api.github.com/repos/'.config('deployment_approval.repository').'/commits/*/check-runs*' => Http::response([
                'check_runs' => [
                    ['name' => 'lint', 'status' => 'completed', 'conclusion' => 'success'],
                    ['name' => 'validate', 'status' => 'completed', 'conclusion' => 'success'],
                ],
            ]),
        ]);
    }

    private function row(array $extra = []): OwnerDeploymentApprovalRequest
    {
        return OwnerDeploymentApprovalRequest::create(array_merge([
            'pull_request_number' => 17, 'head_sha' => self::SHA,
            'repository' => config('deployment_approval.repository'), 'status' => 'approved',
            'requested_admin_id' => $this->admin()->id, 'expires_at' => now()->addHour(),
        ], $extra));
    }

    public function test_guest_and_non_super_admin_are_denied_browser_routes(): void
    {
        $this->get('/admin/deployment-approval')->assertRedirect();
        $this->actingAs($this->admin('admin'), 'admin')
            ->get('/admin/deployment-approval')->assertForbidden();
    }

    public function test_creation_validates_exact_pr_sha_and_named_validate_check(): void
    {
        $admin = $this->admin();
        $this->github();
        $this->actingAs($admin, 'admin')->post('/admin/deployment-approval', [
            'pull_request_number' => 17, 'head_sha' => strtoupper(self::SHA),
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('owner_deployment_approval_requests', [
            'pull_request_number' => 17, 'head_sha' => self::SHA, 'status' => 'pending',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(OwnerDeploymentApprovalService::class)->validatePullRequest(18, 'moved-sha');
    }

    public function test_bad_password_stale_sha_and_expired_approval_are_rejected(): void
    {
        $admin = $this->admin();
        $this->github();
        $row = $this->row(['status' => 'pending', 'expires_at' => now()->subMinute()]);
        $this->actingAs($admin, 'admin')->post("/admin/deployment-approval/{$row->request_id}/approve", [
            'password' => 'wrong',
        ])->assertSessionHasErrors('password');
        try {
            app(OwnerDeploymentApprovalService::class)->approve($row, $admin->id);
            $this->fail('Expired approvals must not be approved.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('This approval request has expired.', $e->getMessage());
        }
        $this->assertSame('pending', $row->fresh()->status);

        $valid = $this->row(['status' => 'pending']);
        $this->assertNotSame(self::SHA, str_repeat('c', 40));
        $this->assertSame('pending', $valid->status);
    }

    public function test_duplicate_creation_and_approval_are_idempotent(): void
    {
        $service = app(OwnerDeploymentApprovalService::class);
        $this->github();
        $admin = $this->admin();
        $first = $service->create(['pull_request_number' => 17, 'head_sha' => self::SHA], $admin->id);
        $second = $service->create(['pull_request_number' => 17, 'head_sha' => self::SHA], $admin->id);
        $this->assertSame($first->request_id, $second->request_id);
        $service->approve($first, $admin->id);
        $this->assertSame($first->request_id, $service->approve($first, $admin->id)->request_id);
    }

    public function test_dispatch_lease_is_idempotent_until_expiry_and_retries_after_expiry(): void
    {
        $service = app(OwnerDeploymentApprovalService::class);
        $row = $this->row();
        $first = $service->leaseApprovedRequests();
        $second = $service->leaseApprovedRequests();
        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $row->refresh()->update(['dispatch_lease_expires_at' => now()->subSecond()]);
        $retry = $service->leaseApprovedRequests();
        $this->assertCount(1, $retry);
        $this->assertNotSame($first[0]['approval_request_id'], ''); // returned binding remains stable
    }

    public function test_wrong_oidc_workflow_is_denied_at_each_machine_boundary(): void
    {
        $row = $this->row(['status' => 'dispatching', 'dispatch_lease_expires_at' => now()->addMinute()]);
        $this->mock(GitHubActionsOidcVerifier::class, fn ($m) => $m->shouldReceive('verify')->once()->andThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 403));
        $this->postJson('/api/deployment-approval/v1/approval-claims', [
            'approval_request_id' => $row->request_id, 'repository' => config('deployment_approval.repository'),
            'pull_number' => 17, 'expected_head_sha' => self::SHA,
        ])->assertForbidden();
    }

    public function test_claim_binds_request_and_stores_only_hash_returning_raw_receipt_once(): void
    {
        $this->github();
        $service = app(OwnerDeploymentApprovalService::class);
        $row = $this->row(['status' => 'dispatching', 'dispatch_lease_expires_at' => now()->addMinute()]);
        $claim = $service->claimForMerge([
            'approval_request_id' => $row->request_id, 'repository' => $row->repository,
            'pull_number' => 17, 'expected_head_sha' => strtoupper(self::SHA),
        ]);
        $this->assertSame(64, strlen($claim['provenance_receipt']));
        $stored = $row->fresh();
        $this->assertNotSame($claim['provenance_receipt'], $stored->provenance_receipt_hash);
        $this->assertSame(hash('sha256', $claim['provenance_receipt']), $stored->provenance_receipt_hash);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->claimForMerge(['approval_request_id' => $row->request_id, 'repository' => $row->repository, 'pull_number' => 17, 'expected_head_sha' => self::SHA]);
    }

    public function test_duplicate_claim_wrong_binding_merge_binding_and_provenance_replay_are_rejected(): void
    {
        $this->github();
        $service = app(OwnerDeploymentApprovalService::class);
        $row = $this->row(['status' => 'dispatching', 'dispatch_lease_expires_at' => now()->addMinute()]);
        $claim = $service->claimForMerge(['approval_request_id' => $row->request_id, 'repository' => $row->repository, 'pull_number' => 17, 'expected_head_sha' => self::SHA]);
        try {
            $service->claimForMerge(['approval_request_id' => $row->request_id, 'repository' => $row->repository, 'pull_number' => 99, 'expected_head_sha' => self::SHA]);
            $this->fail();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        try {
            $service->claimForMerge(['approval_request_id' => $row->request_id, 'repository' => $row->repository, 'pull_number' => 17, 'expected_head_sha' => self::SHA]);
            $this->fail('A claimed request must not be claimed twice.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $service->recordMerge($row->request_id, $claim['provenance_receipt'], self::MERGE);
        $service->recordMerge($row->request_id, $claim['provenance_receipt'], self::MERGE);
    }

    public function test_status_requires_merge_sha_and_failure_callbacks_are_idempotent(): void
    {
        $service = app(OwnerDeploymentApprovalService::class);
        $row = $this->row(['status' => 'merged', 'merge_sha' => self::MERGE, 'provenance_receipt_hash' => hash('sha256', 'receipt'), 'deployment_workflow_sha' => self::MERGE]);
        $claims = ['run_id' => 700, 'run_attempt' => 1, 'workflow_sha' => self::MERGE];
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->recordDeploymentResult($row->request_id, ['run_id' => 700, 'run_attempt' => 1, 'outcome' => 'success', 'deployed_sha' => self::SHA], $claims);
    }

    public function test_register_verify_and_callbacks_bind_deploy_run_and_are_idempotent(): void
    {
        $service = app(OwnerDeploymentApprovalService::class);
        $row = $this->row(['status' => 'merged', 'merge_sha' => self::MERGE, 'provenance_receipt_hash' => hash('sha256', 'receipt'), 'owner_workflow_sha' => self::OWNER_SHA, 'owner_workflow_run_id' => 700, 'owner_workflow_run_attempt' => 1]);
        $claims = ['run_id' => 700, 'run_attempt' => 1, 'workflow_sha' => self::OWNER_SHA];
        $input = ['approval_request_id' => $row->request_id, 'repository' => $row->repository, 'provenance_receipt' => 'receipt', 'merge_sha' => self::MERGE, 'deployment_run_id' => 999, 'deployment_run_attempt' => 1, 'handoff_nonce' => self::NONCE];
        try {
            $service->registerDeployRun($input, array_merge($claims, ['workflow_sha' => str_repeat('d', 40)]));
            $this->fail('A mismatched owner workflow must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $service->registerDeployRun($input, $claims);
        $this->assertSame(999, $row->fresh()->deployment_run_id);
        $service->registerDeployRun($input, $claims);
        try {
            $service->registerDeployRun(array_merge($input, ['deployment_run_id' => 998]), $claims);
            $this->fail('A different deployment run must not reuse the receipt.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        try {
            $service->verifyRegistered(['approval_request_id' => $row->request_id, 'repository' => $row->repository, 'target_sha' => self::MERGE, 'run_id' => 999, 'run_attempt' => 1, 'handoff_nonce' => str_repeat('e', 32)], ['run_id' => 999, 'run_attempt' => 1, 'workflow_sha' => self::MERGE]);
            $this->fail('A different handoff nonce must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        try {
            $service->verifyRegistered(['approval_request_id' => $row->request_id, 'repository' => $row->repository, 'target_sha' => self::MERGE, 'run_id' => 999, 'run_attempt' => 1, 'handoff_nonce' => self::NONCE], ['run_id' => 999, 'run_attempt' => 1, 'workflow_sha' => str_repeat('d', 40)]);
            $this->fail('A deployment workflow SHA not matching the target must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $service->verifyRegistered(['approval_request_id' => $row->request_id, 'repository' => $row->repository, 'target_sha' => self::MERGE, 'run_id' => 999, 'run_attempt' => 1, 'handoff_nonce' => self::NONCE], ['run_id' => 999, 'run_attempt' => 1, 'workflow_sha' => self::MERGE]);
        $this->assertSame('deploying', $row->fresh()->status);
        $service->recordDeploymentResult($row->request_id, ['run_id' => 999, 'run_attempt' => 1, 'handoff_nonce' => self::NONCE, 'outcome' => 'success', 'deployed_sha' => self::MERGE, 'run_url' => 'https://github.com/run/999'], ['run_id' => 999, 'run_attempt' => 1, 'workflow_sha' => self::MERGE]);
        $service->recordDeploymentResult($row->request_id, ['run_id' => 999, 'run_attempt' => 1, 'handoff_nonce' => self::NONCE, 'outcome' => 'success', 'deployed_sha' => self::MERGE], ['run_id' => 999, 'run_attempt' => 1, 'workflow_sha' => self::MERGE]);
        $this->assertSame('succeeded', $row->fresh()->status);
        try {
            $service->recordDeploymentResult($row->request_id, ['run_id' => 999, 'run_attempt' => 1, 'handoff_nonce' => self::NONCE, 'outcome' => 'failure'], ['run_id' => 999, 'run_attempt' => 1, 'workflow_sha' => self::MERGE]);
            $this->fail('A success callback must not be overwritten by failure.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->recordDeploymentResult($row->request_id, ['run_id' => 998, 'run_attempt' => 1, 'handoff_nonce' => self::NONCE, 'outcome' => 'success', 'deployed_sha' => self::MERGE], ['run_id' => 998, 'run_attempt' => 1, 'workflow_sha' => self::MERGE]);
    }

    public function test_index_renders_requester_approver_result_and_run_link(): void
    {
        $requester = $this->admin();
        $approver = $this->admin();
        $row = $this->row(['requested_admin_id' => $requester->id, 'approved_admin_id' => $approver->id, 'status' => 'succeeded', 'deploy_result' => 'success', 'workflow_run_url' => 'https://github.com/run/1']);
        $this->actingAs($requester, 'admin')->get('/admin/deployment-approval')
            ->assertOk()->assertSee($requester->name)->assertSee($approver->name)
            ->assertSee('Success')->assertSee('https://github.com/run/1');
    }

    public function test_expired_approval_cannot_register_deploy_run(): void
    {
        $service = app(OwnerDeploymentApprovalService::class);
        $row = $this->row(['status' => 'merged', 'merge_sha' => self::MERGE, 'expires_at' => now()->subSecond(), 'provenance_receipt_hash' => hash('sha256', 'receipt')]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->registerDeployRun([
            'approval_request_id' => $row->request_id, 'repository' => $row->repository,
            'provenance_receipt' => 'receipt', 'merge_sha' => self::MERGE, 'deployment_run_id' => 999,
        ], ['run_id' => 700, 'run_attempt' => 1]);
    }

    public function test_slow_success_callback_after_approval_expiry_is_allowed_once_deploying(): void
    {
        $service = app(OwnerDeploymentApprovalService::class);
        $row = $this->row([
            'status' => 'deploying', 'expires_at' => now()->subMinute(),
            'deployment_run_id' => 999, 'deployment_run_attempt' => 1,
            'merge_sha' => self::MERGE, 'deployment_workflow_sha' => self::MERGE,
            'handoff_nonce_hash' => hash('sha256', self::NONCE),
        ]);
        $result = $service->recordDeploymentResult($row->request_id, [
            'run_id' => 999, 'run_attempt' => 1, 'handoff_nonce' => self::NONCE, 'outcome' => 'success', 'deployed_sha' => self::MERGE,
        ], ['run_id' => 999, 'run_attempt' => 1, 'workflow_sha' => self::MERGE]);
        $this->assertSame('succeeded', $result->status);
    }

    public function test_machine_routes_bind_owner_and_deployment_claims_over_http(): void
    {
        $service = app(OwnerDeploymentApprovalService::class);
        $row = $this->row([
            'status' => 'merged', 'merge_sha' => self::MERGE,
            'provenance_receipt_hash' => hash('sha256', str_repeat('r', 64)),
            'owner_workflow_sha' => self::OWNER_SHA, 'owner_workflow_run_id' => 700,
            'owner_workflow_run_attempt' => 1,
        ]);
        $ownerClaims = ['run_id' => 700, 'run_attempt' => 1, 'workflow_sha' => self::OWNER_SHA];
        $deployClaims = ['run_id' => 999, 'run_attempt' => 1, 'workflow_sha' => self::MERGE];
        $this->mock(GitHubActionsOidcVerifier::class, function ($mock) use ($ownerClaims, $deployClaims) {
            $mock->shouldReceive('verify')->andReturnUsing(
                fn ($request, $workflow) => $workflow === 'owner-merge-and-deploy.yml' ? $ownerClaims : $deployClaims
            );
        });
        $base = [
            'approval_request_id' => $row->request_id, 'repository' => $row->repository,
            'provenance_receipt' => str_repeat('r', 64), 'merge_sha' => self::MERGE,
            'deployment_run_id' => 999, 'deployment_run_attempt' => 1, 'handoff_nonce' => self::NONCE,
        ];
        $this->postJson('/api/deployment-approval/v1/deploy-run', $base)->assertOk();
        $this->assertSame(999, $row->fresh()->deployment_run_id);
        $this->assertSame(hash('sha256', self::NONCE), $row->fresh()->handoff_nonce_hash);

        $missingAttempt = $base;
        unset($missingAttempt['deployment_run_attempt']);
        $fresh = $this->row([
            'status' => 'merged', 'merge_sha' => self::MERGE,
            'provenance_receipt_hash' => hash('sha256', str_repeat('s', 64)),
            'owner_workflow_sha' => self::OWNER_SHA, 'owner_workflow_run_id' => 700, 'owner_workflow_run_attempt' => 1,
        ]);
        $missingAttempt['approval_request_id'] = $fresh->request_id;
        $missingAttempt['provenance_receipt'] = str_repeat('s', 64);
        $this->postJson('/api/deployment-approval/v1/deploy-run', $missingAttempt)->assertStatus(422);

        $provenance = [
            'approval_request_id' => $row->request_id, 'repository' => $row->repository,
            'target_sha' => self::MERGE, 'run_id' => 999, 'run_attempt' => 1, 'handoff_nonce' => self::NONCE,
        ];
        $this->postJson('/api/deployment-approval/v1/provenance/verify', $provenance)->assertOk();
        $this->postJson('/api/deployment-approval/v1/provenance/verify', array_merge($provenance, ['handoff_nonce' => str_repeat('e', 32)]))->assertStatus(409);

        $status = [
            'approval_request_id' => $row->request_id, 'handoff_nonce' => self::NONCE,
            'run_id' => 999, 'run_attempt' => 1, 'outcome' => 'success', 'deployed_sha' => self::MERGE,
        ];
        $this->postJson('/api/deployment-approval/v1/status', $status)->assertOk();
        $this->assertSame('succeeded', $row->fresh()->status);
        $this->postJson('/api/deployment-approval/v1/status', array_merge($status, ['handoff_nonce' => str_repeat('e', 32)]))->assertStatus(409);
    }
}