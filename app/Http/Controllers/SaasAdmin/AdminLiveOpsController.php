<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\LiveOpsAgentCommand;
use App\Models\LiveOpsAuditEvent;
use App\Models\LiveOpsDiagnosticReport;
use App\Models\LiveOpsRemediationRequest;
use App\Services\LiveOps\LiveOpsDiagnosticsService;
use App\Services\LiveOps\LiveOpsRemediationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Super-admin Live Ops console — diagnostics, proposals, approvals, history.
 */
class AdminLiveOpsController extends Controller
{
    private function gate(): void
    {
        if ((auth('admin')->user()->role ?? null) !== 'super_admin') {
            abort(403);
        }
    }

    public function index(Request $request)
    {
        $this->gate();

        $recentReports = Schema::hasTable('live_ops_diagnostic_reports')
            ? LiveOpsDiagnosticReport::orderByDesc('id')->limit(20)->get()
            : collect();
        $recentRemediations = Schema::hasTable('live_ops_remediation_requests')
            ? LiveOpsRemediationRequest::orderByDesc('id')->limit(20)->get()
            : collect();
        $recentCommands = Schema::hasTable('live_ops_agent_commands')
            ? LiveOpsAgentCommand::orderByDesc('id')->limit(20)->get()
            : collect();
        $recentAudit = Schema::hasTable('live_ops_audit_events')
            ? LiveOpsAuditEvent::orderByDesc('id')->limit(30)->get()
            : collect();

        $q = trim((string) $request->query('q', ''));
        $companies = collect();
        if ($q !== '') {
            $companies = Company::query()
                ->whereIn('product_type', config('live_ops.product_types', ['pos']))
                ->where(function ($w) use ($q) {
                    $w->where('name', 'like', '%'.$q.'%');
                    if (ctype_digit($q)) {
                        $w->orWhere('id', (int) $q);
                    }
                    if (Schema::hasColumn('companies', 'account_code')) {
                        $w->orWhere('account_code', 'like', '%'.$q.'%');
                    }
                })
                ->orderBy('name')
                ->limit(25)
                ->get(['id', 'name', 'account_code', 'agent_last_seen', 'agent_enabled', 'agent_version']);
        }

        return view('saas-admin.live-ops.index', [
            'q' => $q,
            'companies' => $companies,
            'recentReports' => $recentReports,
            'recentRemediations' => $recentRemediations,
            'recentCommands' => $recentCommands,
            'recentAudit' => $recentAudit,
            'operations' => config('live_ops.diagnostic_operations', []),
            'actions' => array_merge(
                config('live_ops.remediation_actions.low', []),
                config('live_ops.remediation_actions.medium', []),
            ),
            'approvalPhrase' => LiveOpsRemediationService::APPROVAL_PHRASE,
        ]);
    }

    public function diagnose(Request $request, LiveOpsDiagnosticsService $diagnostics)
    {
        $this->gate();
        $validated = $request->validate([
            'operation' => 'required|string|max:64',
            'company_id' => 'nullable|integer|min:1',
            'company_name' => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        try {
            $report = $diagnostics->run($validated['operation'], [
                'company_id' => $validated['company_id'] ?? null,
                'company_name' => $validated['company_name'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'requester' => auth('admin')->user()->email ?? 'admin',
                'admin_id' => auth('admin')->id(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return view('saas-admin.live-ops.report', [
            'report' => $report,
        ]);
    }

    public function showCompany(int $id, LiveOpsDiagnosticsService $diagnostics)
    {
        $this->gate();
        try {
            $report = $diagnostics->run('COMPANY_DIAGNOSTIC', [
                'company_id' => $id,
                'requester' => auth('admin')->user()->email ?? 'admin',
                'admin_id' => auth('admin')->id(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return view('saas-admin.live-ops.report', ['report' => $report]);
    }

    public function propose(Request $request, LiveOpsRemediationService $remediation)
    {
        $this->gate();
        $validated = $request->validate([
            'action' => 'required|string|max:64',
            'company_id' => 'required|integer|min:1',
            'parameters_json' => 'nullable|string',
            'proposal' => 'nullable|string|max:2000',
            'idempotency_key' => 'nullable|string|max:80',
        ]);
        $params = [];
        if (!empty($validated['parameters_json'])) {
            $decoded = json_decode($validated['parameters_json'], true);
            if (!is_array($decoded)) {
                return back()->with('error', 'parameters_json must be a JSON object');
            }
            $params = $decoded;
        }

        try {
            $row = $remediation->propose([
                'action' => $validated['action'],
                'company_id' => $validated['company_id'],
                'parameters' => $params,
                'proposal' => $validated['proposal'] ?? null,
                'idempotency_key' => $validated['idempotency_key'] ?? null,
                'requester' => auth('admin')->user()->email ?? 'admin',
                'admin_id' => auth('admin')->id(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('saas.admin.live-ops.remediation', $row->action_id)
            ->with('success', 'Remediation proposed: '.$row->action_id);
    }

    public function showRemediation(string $actionId)
    {
        $this->gate();
        $row = LiveOpsRemediationRequest::where('action_id', $actionId)->firstOrFail();

        return view('saas-admin.live-ops.remediation', [
            'row' => $row,
            'approvalPhrase' => LiveOpsRemediationService::APPROVAL_PHRASE,
        ]);
    }

    public function approve(Request $request, string $actionId, LiveOpsRemediationService $remediation)
    {
        $this->gate();
        $validated = $request->validate([
            'owner_approval_phrase' => 'required|string',
            'execute_now' => 'nullable|boolean',
        ]);

        try {
            $row = $remediation->approve($actionId, [
                'owner_approval_phrase' => $validated['owner_approval_phrase'],
                'approved_by' => auth('admin')->user()->email ?? 'admin',
                'admin_id' => auth('admin')->id(),
            ]);
            if (!empty($validated['execute_now'])) {
                $row = $remediation->execute($actionId, [
                    'executor' => auth('admin')->user()->email ?? 'admin',
                ]);
            }
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('saas.admin.live-ops.remediation', $row->action_id)
            ->with('success', 'Remediation '.$row->status);
    }

    public function reject(string $actionId, LiveOpsRemediationService $remediation)
    {
        $this->gate();
        try {
            $row = $remediation->reject($actionId, [
                'rejected_by' => auth('admin')->user()->email ?? 'admin',
                'admin_id' => auth('admin')->id(),
            ]);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('saas.admin.live-ops.remediation', $row->action_id)
            ->with('success', 'Remediation rejected');
    }

    public function execute(string $actionId, LiveOpsRemediationService $remediation)
    {
        $this->gate();
        try {
            $row = $remediation->execute($actionId, [
                'executor' => auth('admin')->user()->email ?? 'admin',
            ]);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('saas.admin.live-ops.remediation', $row->action_id)
            ->with('success', 'Executed: '.$row->status);
    }
}
