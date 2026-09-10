<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\OwnerDeploymentApprovalRequest;
use App\Services\GitHubActionsOidcVerifier;
use App\Services\OwnerDeploymentApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class OwnerDeploymentApprovalController extends Controller
{
    public function index()
    {
        $this->authorizeOwner();

        return view('saas-admin.deployment-approval.index', [
            'requests' => OwnerDeploymentApprovalRequest::query()
                ->with(['requestedBy:id,name', 'approvedBy:id,name'])
                ->latest()
                ->limit(25)
                ->get(),
        ]);
    }

    public function store(Request $request, OwnerDeploymentApprovalService $service)
    {
        $this->authorizeOwner();
        $input = $request->validate([
            'pull_request_number' => ['required', 'integer', 'min:1'],
            'head_sha' => ['required', 'regex:/^[0-9a-fA-F]{40}$/'],
        ]);

        try {
            $approval = $service->create($input, auth('admin')->id());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['pull_request_number' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', "Deployment request {$approval->request_id} is ready for approval.");
    }

    public function approve(
        Request $request,
        string $requestId,
        OwnerDeploymentApprovalService $service
    ) {
        $this->authorizeOwner();
        $request->validate(['password' => ['required', 'string']]);

        if (!Hash::check((string) $request->input('password'), auth('admin')->user()->password)) {
            return back()->withErrors(['password' => 'Current admin password is incorrect.']);
        }

        try {
            $approval = OwnerDeploymentApprovalRequest::findOrFail($requestId);
            $service->approve($approval, auth('admin')->id());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['password' => $exception->getMessage()]);
        }

        return back()->with('success', 'Exact PR and HEAD SHA approved for deployment.');
    }

    public function dispatchClaims(
        Request $request,
        GitHubActionsOidcVerifier $oidc,
        OwnerDeploymentApprovalService $service
    ) {
        $claims = $oidc->verify($request, 'approval-dispatch.yml');
        $request->validate(['repository' => ['required', 'in:'.config('deployment_approval.repository')]]);

        return response()->json(['claims' => $service->leaseApprovedRequests(), 'run_id'=>(int)($claims['run_id']??0), 'run_attempt'=>(int)($claims['run_attempt']??0)]);
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

        return response()->json($service->claimForMerge($input, $claims));
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

    private function authorizeOwner(): void
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);
    }
}