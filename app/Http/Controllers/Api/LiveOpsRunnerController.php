<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveOpsRemediationRequest;
use App\Services\LiveOps\LiveOpsAutonomousEngine;
use App\Services\LiveOps\LiveOpsDiagnosticsService;
use App\Services\LiveOps\LiveOpsRemediationService;
use App\Services\OwnerDeploymentApprovalService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Token-gated runner API for GitHub Actions trusted jobs.
 * Token lives ONLY in GitHub Environment production / server env — never Cloud Agent.
 */
class LiveOpsRunnerController extends Controller
{
    private function authorizeRunner(Request $request): void
    {
        $expected = (string) config('live_ops.runner_token', '');
        if ($expected === '' || strlen($expected) < 32) {
            abort(503, 'LIVE_OPS_RUNNER_TOKEN not configured');
        }
        $provided = (string) $request->header('X-Live-Ops-Token', '');
        if ($provided === '' || !hash_equals($expected, $provided)) {
            abort(401, 'Unauthorized');
        }
    }

    public function diagnose(Request $request, LiveOpsDiagnosticsService $diagnostics)
    {
        $this->authorizeRunner($request);
        $validated = $request->validate([
            'operation' => 'required|string|max:64',
            'company_id' => 'nullable|integer|min:1',
            'company_name' => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'requester' => 'nullable|string|max:120',
        ]);

        try {
            $report = $diagnostics->run($validated['operation'], $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'report' => $report]);
    }

    public function propose(Request $request, LiveOpsRemediationService $remediation)
    {
        $this->authorizeRunner($request);
        $validated = $request->validate([
            'action' => 'required|string|max:64',
            'company_id' => 'required|integer|min:1',
            'parameters' => 'nullable|array',
            'proposal' => 'nullable|string|max:2000',
            'idempotency_key' => 'nullable|string|max:80',
            'requester' => 'nullable|string|max:120',
            'evidence' => 'nullable|array',
        ]);

        try {
            $row = $remediation->propose($validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'remediation' => $row]);
    }

    public function approve(Request $request, string $actionId, LiveOpsRemediationService $remediation)
    {
        $this->authorizeRunner($request);
        $validated = $request->validate([
            'owner_approval_phrase' => 'required|string',
            'approved_by' => 'nullable|string|max:120',
        ]);

        try {
            $row = $remediation->approve($actionId, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'remediation' => $row]);
    }

    public function execute(Request $request, string $actionId, LiveOpsRemediationService $remediation)
    {
        $this->authorizeRunner($request);
        try {
            $row = $remediation->execute($actionId, [
                'executor' => $request->input('executor', 'github-actions'),
            ]);
        } catch (\InvalidArgumentException $e) {
            // Domain guard messages (expired / not approved / high-risk blocked)
            // are intentionally surfaced to the runner.
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['ok' => false, 'error' => 'Remediation request not found'], 404);
        } catch (\Throwable $e) {
            // Unexpected failures must not leak internals (SQL, paths, hosts)
            // to the runner. The full exception goes to the log under a
            // correlation id the runner can quote back.
            $correlationId = (string) Str::uuid();
            $companyId = null;
            try {
                $companyId = LiveOpsRemediationRequest::where('action_id', $actionId)->value('company_id');
            } catch (\Throwable $ignored) {
                // Lookup is best-effort context for the log only.
            }
            Log::error('LIVE_OPS remediation execution failed', [
                'correlation_id' => $correlationId,
                'action_id' => $actionId,
                'company_id' => $companyId,
                'executor' => $request->input('executor', 'github-actions'),
                'exception' => $e,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'Remediation execution failed',
                'correlation_id' => $correlationId,
            ], 500);
        }

        return response()->json(['ok' => true, 'remediation' => $row]);
    }

    public function ownerCommand(Request $request, LiveOpsAutonomousEngine $engine)
    {
        $this->authorizeRunner($request);
        $validated = $request->validate([
            'text' => 'required|string|max:500',
            'requester' => 'nullable|string|max:120',
            'source' => 'nullable|string|max:80',
            'request_id' => 'nullable|string|max:64',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        try {
            $result = $engine->handle($validated['text'], [
                'requester' => $validated['requester'] ?? 'github-actions',
                'source' => $validated['source'] ?? 'runner',
                'request_id' => $validated['request_id'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'result' => $result]);
    }

    public function safeAutoDeploy(Request $request, OwnerDeploymentApprovalService $approvals)
    {
        $this->authorizeRunner($request);
        $validated = $request->validate([
            'pull_request_number' => 'required|integer|min:1',
            'head_sha' => 'required|string|size:40',
            'paths' => 'required|array|min:1|max:200',
            'paths.*' => 'string|max:255',
            'requester' => 'nullable|string|max:120',
        ]);

        try {
            $row = $approvals->approveOrdinarySafeFix($validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'approval_request_id' => $row->request_id,
            'status' => $row->status,
            'pull_request_number' => $row->pull_request_number,
            'head_sha' => $row->head_sha,
        ]);
    }
}
