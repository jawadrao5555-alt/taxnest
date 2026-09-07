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
 * Super-admin Live Ops console — NestPOS PRA diagnostics + owner-approved remediation.
 *
 * Authorization mirrors AdminLiveActivityController / Support Inbox:
 * SaaS AdminUser with isSuperAdmin() only. This is NOT the POS panel.
 *
 * POS company_admin / pos_manager / cashier / viewer / archive_viewer / local_viewer
 * authenticate on the users table (pos/web guards) and cannot reach /admin/* Live Ops.
 * There is no users↔companies multi-company staff pivot; one user → one company_id.
 * Cross-company SaaS visibility is super_admin-only (same as Live Activity).
 */
class AdminLiveOpsController extends Controller
{
    private function gate(): void
    {
        $admin = auth('admin')->user();
        if (!$admin || !$admin->isSuperAdmin()) {
            abort(403, 'Super admin only.');
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

        // Same filter shape as /admin/companies (search + status), scoped to NestPOS PRA.
        $search = trim((string) $request->query('search', $request->query('q', '')));
        $status = trim((string) $request->query('status', ''));
        $companies = collect();
        if ($search !== '' || $status !== '') {
            $companies = Company::query()
                ->whereIn('product_type', config('live_ops.product_types', ['pos']))
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->when($search !== '', function ($q) use ($search) {
                    $q->where(function ($w) use ($search) {
                        $w->where('name', 'like', '%'.$search.'%')
                            ->orWhere('owner_name', 'like', '%'.$search.'%')
                            ->orWhere('ntn', 'like', '%'.$search.'%');
                        if (ctype_digit($search)) {
                            $w->orWhere('id', (int) $search);
                        }
                        if (Schema::hasColumn('companies', 'account_code')) {
                            $w->orWhere('account_code', 'like', '%'.$search.'%');
                        }
                    });
                })
                ->orderBy('name')
                ->limit(25)
                ->get(array_values(array_filter([
                    'id', 'name', 'status', 'agent_last_seen', 'agent_enabled', 'agent_version', 'owner_name',
                    Schema::hasColumn('companies', 'account_code') ? 'account_code' : null,
                    Schema::hasColumn('companies', 'ntn') ? 'ntn' : null,
                ])));
        }

        return view('saas-admin.live-ops.index', [
            'search' => $search,
            'status' => $status,
            'q' => $search, // backward-compatible alias for older links
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
