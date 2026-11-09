<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Exceptions\DeploymentGitHubUnavailable;
use App\Models\OwnerDeploymentApprovalRequest;
use App\Models\AdminUser;
use App\Services\EligibleDeploymentPullRequestService;
use App\Services\DeploymentReleaseHistory;
use App\Services\GitHubActionsOidcVerifier;
use App\Services\OwnerDeploymentApprovalService;
use App\Support\OwnerApprovalPickupStatus;
use App\Support\OwnerApprovalPollerHeartbeat;
use App\Support\OwnerApprovalImmediateDispatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class OwnerDeploymentApprovalController extends Controller
{
    public function index(Request $request, EligibleDeploymentPullRequestService $github, DeploymentReleaseHistory $history)
    {
        $this->authorizeOwner();

        $showHistory = $request->boolean('history');
        $releases = null;
        $historyWarning = null;
        $historyFilters = [];
        if ($showHistory) {
            $historyFilters = $request->validate([
                'q' => ['nullable', 'string', 'max:120'],
                'state' => ['nullable', 'in:'.implode(',', array_keys(DeploymentReleaseHistory::STATES))],
                'module' => ['nullable', 'in:'.implode(',', array_keys(DeploymentReleaseHistory::MODULES))],
                'deployment' => ['nullable', 'in:'.implode(',', array_keys(DeploymentReleaseHistory::DEPLOYMENTS))],
                'from' => ['nullable', 'date_format:Y-m-d'],
                'to' => ['nullable', 'date_format:Y-m-d'],
            ]);
            try {
                $releases = $history->paginate($historyFilters);
            } catch (DeploymentGitHubUnavailable $exception) {
                $historyWarning = $exception->getMessage();
            }
        }
        $eligiblePullRequests = $showHistory ? [] : $github->eligible();

        $requests = OwnerDeploymentApprovalRequest::query()
            ->with(['requestedBy:id,name', 'approvedBy:id,name'])
            ->latest()
            ->limit(25)
            ->get();
        $poller = OwnerApprovalPickupStatus::summarize($requests);

        return view('saas-admin.deployment-approval.index', [
            'requests' => $requests,
            'showHistory' => $showHistory,
            'releases' => $releases,
            'historyWarning' => $historyWarning,
            'historyFilters' => $historyFilters,
            'eligiblePullRequests' => $eligiblePullRequests,
            'eligibilityWarning' => $github->availabilityWarning(),
            'immediateDispatch' => OwnerApprovalImmediateDispatch::status(),
            'poller' => $poller,
            'requestStatuses' => $requests->mapWithKeys(
                fn ($row) => [$row->request_id => OwnerApprovalPickupStatus::forRequest($row, $poller)]
            ),
        ]);
    }

    public function store(
        Request $request,
        OwnerDeploymentApprovalService $service,
        EligibleDeploymentPullRequestService $github,
        OwnerApprovalImmediateDispatch $immediateDispatch
    ) {
        $actor = $this->authorizeOwner();
        $input = $request->validate([
            'pull_request_number' => ['required', 'integer', 'min:1'],
            'password' => ['required', 'string'],
            'merge_sha' => ['nullable', 'regex:/^[a-f0-9]{40}$/i'],
        ]);

        if (!Hash::check((string) $request->input('password'), $actor->password)) {
            return back()->withErrors(['password' => 'Current admin password is incorrect.'])->withInput($request->only('pull_request_number', 'merge_sha'));
        }

        try {
            if (!empty($input['merge_sha'])) {
                $approval = $service->requestMergedRelease(
                    (int) $input['pull_request_number'], $input['merge_sha'], $actor->id
                );
            } else {
                $candidate = $github->resolve((int) $input['pull_request_number']);
                $approval = $service->create([
                    'pull_request_number' => $candidate['number'],
                    'head_sha' => $candidate['head_sha'],
                ], $actor->id);
            }
            $approval = $service->approve($approval, $actor->id);
            $immediateDispatch->dispatch($approval);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['pull_request_number' => $exception->getMessage()])->withInput($request->only('pull_request_number', 'merge_sha'));
        }

        return back()->with('success', "PR #{$approval->pull_request_number} approved. One approval is enough. GitHub's scheduled Approval Relay will pick this up — do not approve again. If pickup is delayed, use GitHub Actions → Approval Relay Dispatch → Run workflow. Immediate auto-dispatch would need a GitHub App installation token, which this app does not store.");
    }

    public function review(Request $request, string $requestId)
    {
        $actor = $this->authorizeOwner();
        abort_unless((int) $request->query('approver') === (int) $actor->id, 403);

        $approval = OwnerDeploymentApprovalRequest::findOrFail($requestId);
        abort_unless($approval->status === 'pending' && $approval->isUnexpired(), 410);

        return redirect()->route('saas.admin.deployment-approval', ['review' => $approval->request_id])
            ->with('success', 'Secure email link verified. Review the exact PR and enter your current password to approve.');
    }

    public function approve(
        Request $request,
        string $requestId,
        OwnerDeploymentApprovalService $service,
        OwnerApprovalImmediateDispatch $immediateDispatch
    ) {
        $actor = $this->authorizeOwner();
        $request->validate(['password' => ['required', 'string']]);

        if (!Hash::check((string) $request->input('password'), $actor->password)) {
            return back()->withErrors(['password' => 'Current admin password is incorrect.']);
        }

        try {
            $approval = OwnerDeploymentApprovalRequest::findOrFail($requestId);
            $approval = $service->approve($approval, $actor->id);
            $immediateDispatch->dispatch($approval);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['password' => $exception->getMessage()]);
        }

        return back()->with('success', 'Exact PR and HEAD SHA approved. Do not approve again. Wait for the scheduled Approval Relay, or use GitHub Actions → Approval Relay Dispatch → Run workflow if the poller heartbeat is stale.');
    }

    public function dispatchClaims(
        Request $request,
        GitHubActionsOidcVerifier $oidc,
        OwnerDeploymentApprovalService $service
    ) {
        $claims = $oidc->verify($request, 'approval-dispatch.yml');
        $request->validate(['repository' => ['required', 'in:'.config('deployment_approval.repository')]]);

        $polledAt = OwnerApprovalPollerHeartbeat::record(
            now(),
            (int) ($claims['run_id'] ?? 0),
            (int) ($claims['run_attempt'] ?? 0)
        );

        return response()->json([
            'claims' => $service->leaseApprovedRequests(),
            'run_id' => (int) ($claims['run_id'] ?? 0),
            'run_attempt' => (int) ($claims['run_attempt'] ?? 0),
            'polled_at' => $polledAt->toIso8601String(),
        ]);
    }

    public function claim(
        Request $request,
        GitHubActionsOidcVerifier $oidc,
        OwnerDeploymentApprovalService $service
    ) {
        $claims = $oidc->verify($request, 'owner-merge-and-deploy.yml');
        $input = $request->validate([
            'approval_request_id' => ['required', 'uuid'],
            'repository' => ['required', 'in:'.config('deployment_approval.repository')],
            'pull_number' => ['required', 'integer', 'min:1'],
            'expected_head_sha' => ['required', 'regex:/^[0-9a-fA-F]{40}$/'],
        ]);

        try {
            return response()->json($service->claimForMerge($input, $claims));
        } catch (DeploymentGitHubUnavailable $exception) {
            return response()->json([
                'code' => 'github_validation_unavailable',
                'message' => $exception->getMessage(),
                'stage' => $exception->stage,
                'upstream_status' => $exception->upstreamStatus,
                'retry_after' => $exception->retryAfter,
            ], 503)->header('Retry-After', (string) $exception->retryAfter);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'code' => 'deployment_ineligible',
                'message' => $exception->getMessage(),
            ], 409);
        }
    }

    public function mergeComplete(
        Request $request,
        GitHubActionsOidcVerifier $oidc,
        OwnerDeploymentApprovalService $service
    ) {
        $claims = $oidc->verify($request, 'owner-merge-and-deploy.yml');
        $input = $request->validate([
            'approval_request_id' => ['required', 'uuid'],
            'provenance_receipt' => ['required', 'string', 'size:64'],
            'repository' => ['required', 'in:'.config('deployment_approval.repository')],
            'merge_sha' => ['required', 'regex:/^[0-9a-fA-F]{40}$/'],
        ]);

        $service->recordMerge(
            $input['approval_request_id'],
            $input['provenance_receipt'],
            $input['merge_sha'], $claims
        );

        return response()->json(['ok' => true]);
    }

    public function ownerStatus(
        Request $request,
        GitHubActionsOidcVerifier $oidc,
        OwnerDeploymentApprovalService $service
    ) {
        $claims = $oidc->verify($request, 'owner-merge-and-deploy.yml');
        $input = $request->validate([
            'approval_request_id' => ['required', 'uuid'],
            'outcome' => ['required', 'in:failure,cancelled'],
            'failure_summary' => ['nullable', 'string', 'max:2000'],
        ]);

        $approval = $service->recordOwnerWorkflowFailure(
            $input['approval_request_id'],
            $input,
            $claims
        );

        return response()->json([
            'ok' => true,
            'status' => $approval->status,
            'request_id' => $approval->request_id,
        ]);
    }

    public function provenance(
        Request $request,
        GitHubActionsOidcVerifier $oidc,
        OwnerDeploymentApprovalService $service
    ) {
        $claims = $oidc->verify($request, 'deploy-production.yml');
        $input = $request->validate([
            'approval_request_id' => ['required', 'uuid'],
            'repository' => ['required', 'in:'.config('deployment_approval.repository')],
            'target_sha' => ['required', 'regex:/^[0-9a-fA-F]{40}$/'],
            'handoff_nonce' => ['required', 'regex:/^[0-9a-f]{32}$/'],
            'run_id' => ['required','integer','min:1'],
            'run_attempt' => ['required','integer','min:1'],
        ]);
        $service->verifyRegistered($input, $claims);

        return response()->json(['valid' => true]);
    }

    public function deployRun(Request $request, GitHubActionsOidcVerifier $oidc, OwnerDeploymentApprovalService $service)
    {
        $claims = $oidc->verify($request, 'owner-merge-and-deploy.yml');
        $input = $request->validate([
            'approval_request_id'=>['required','uuid'],
            'provenance_receipt'=>['required','string','size:64'],
            'repository' => ['required', 'in:'.config('deployment_approval.repository')],
            'merge_sha'=>['required','regex:/^[0-9a-fA-F]{40}$/'],
            'deployment_run_id' => ['required', 'integer', 'min:1'],
            'deployment_run_attempt' => ['required', 'integer', 'min:1'],
            'handoff_nonce' => ['required','regex:/^[0-9a-f]{32}$/'],
        ]);
        $service->registerDeployRun($input, $claims);
        return response()->json(['ok'=>true]);
    }

    public function status(
        Request $request,
        GitHubActionsOidcVerifier $oidc,
        OwnerDeploymentApprovalService $service
    ) {
        $claims = $oidc->verify($request, 'deploy-production.yml');
        $input = $request->validate([
            'approval_request_id' => ['required', 'uuid'],
            'handoff_nonce' => ['required', 'regex:/^[0-9a-f]{32}$/'],
            'outcome' => ['required', 'in:success,failure,cancelled,skipped'],
            'run_id' => ['required','integer','min:1'],
            'run_attempt' => ['required','integer','min:1'],
            'run_url' => ['nullable', 'url', 'max:500'],
            'deployed_sha' => ['nullable', 'regex:/^[0-9a-fA-F]{40}$/'],
            'failure_summary' => ['nullable', 'string', 'max:2000'],
        ]);

        $approval = $service->recordDeploymentResult($input['approval_request_id'], $input, $claims);

        return response()->json([
            'ok' => true,
            'status' => $approval->status,
            'request_id' => $approval->request_id,
        ]);
    }

    private function authorizeOwner(): AdminUser
    {
        return AdminUser::requireSuperAdministrator();
    }
}
