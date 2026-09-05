<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AgentSaleClaim;
use App\Models\Company;
use App\Models\PaymentProof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAgentClaimController extends Controller
{
    private function assertSuperAdmin(): void
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403, 'Super admin only.');
    }

    public function index()
    {
        $this->assertSuperAdmin();
        $claims = AgentSaleClaim::with(['agent', 'company'])->orderByDesc('id')->paginate(40);
        return view('saas-admin.agents.claims', compact('claims'));
    }

    public function review(Request $request, AgentSaleClaim $claim)
    {
        $this->assertSuperAdmin();
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($claim, $data) {
            $lockedClaim = AgentSaleClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($lockedClaim->status !== 'pending') {
                return 'processed';
            }

            if ($data['decision'] === 'reject') {
                $lockedClaim->update([
                    'status' => 'rejected',
                    'admin_note' => $data['admin_note'] ?? null,
                    'reviewed_by_admin_id' => auth('admin')->id(),
                    'reviewed_at' => now(),
                ]);
                AdminAuditLog::log(auth('admin')->id(), 'Agent sale claim rejected', 'AgentSaleClaim', $lockedClaim->id, [
                    'agent_id' => $lockedClaim->agent_id,
                    'admin_note' => $data['admin_note'] ?? null,
                ]);
                return 'rejected';
            }

            $query = Company::where($lockedClaim->identifier_type, $lockedClaim->identifier);
            $company = $query->lockForUpdate()->first();
            if (!$company) {
                return 'not_found';
            }
            if (PaymentProof::subscriptionKind()->where('company_id', $company->id)->where('status', 'verified')->exists()) {
                return 'attribution_locked';
            }
            if ($company->agent_id !== null) {
                return (int) $company->agent_id === (int) $lockedClaim->agent_id ? 'already_owned' : 'owned';
            }

            $company->update(['agent_id' => $lockedClaim->agent_id]);
            $lockedClaim->update([
                'status' => 'approved',
                'company_id' => $company->id,
                'admin_note' => $data['admin_note'] ?? null,
                'reviewed_by_admin_id' => auth('admin')->id(),
                'reviewed_at' => now(),
            ]);
            AdminAuditLog::log(auth('admin')->id(), 'Agent sale claim approved', 'AgentSaleClaim', $lockedClaim->id, [
                'agent_id' => $lockedClaim->agent_id, 'company_id' => $company->id,
            ]);
            return 'approved';
        });

        $messages = [
            'approved' => ['success', 'Claim approved and company assigned.'],
            'rejected' => ['success', 'Claim rejected.'],
            'processed' => ['error', 'This claim was already reviewed.'],
            'attribution_locked' => ['error', 'Distributor attribution is locked after the first verified subscription payment. Use the audited super-admin override action.'],
            'not_found' => ['error', 'No company matches that identifier. Reject the claim or ask the agent to correct it.'],
            'owned' => ['error', 'This company already belongs to another agent and cannot be reassigned.'],
            'already_owned' => ['error', 'This company is already assigned to the claiming agent.'],
        ];
        [$kind, $message] = $messages[$result];
        return back()->with($kind, $message);
    }
}