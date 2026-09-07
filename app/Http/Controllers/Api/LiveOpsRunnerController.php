<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LiveOps\LiveOpsDiagnosticsService;
use App\Services\LiveOps\LiveOpsRemediationService;
use Illuminate\Http\Request;

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
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'remediation' => $row]);
    }
}
