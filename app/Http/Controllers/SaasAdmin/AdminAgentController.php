<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\AgentIncentiveAward;
use App\Services\AgentCommissionService;
use App\Services\DistributorIncentiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Agents/Partners program (Agency Agreement Model A). Super-admin only:
 * agent register, per-agent customer list, cleared payments, and the monthly
 * Schedule A commission report (earn + clawback ledger) with CSV export.
 */
class AdminAgentController extends Controller
{
    /** Super-admin gate — the whole section is invisible to normal admins. */
    private function assertSuperAdmin(): void
    {
        $admin = auth('admin')->user();
        if (!$admin || !$admin->isSuperAdmin()) {
            abort(403, 'Super admin only.');
        }
    }

    public function index()
    {
        $this->assertSuperAdmin();

        if (!Schema::hasTable('agents')) {
            return view('saas-admin.agents.index', [
                'agents' => collect(), 'companyCounts' => collect(),
                'earnedTotals' => collect(), 'tableMissing' => true,
            ]);
        }

        $agents = Agent::orderByDesc('id')->get();

        $companyCounts = Company::whereNotNull('agent_id')
            ->selectRaw('agent_id, COUNT(*) as c')
            ->groupBy('agent_id')
            ->pluck('c', 'agent_id');

        $earnedTotals = AgentCommission::selectRaw('agent_id, SUM(amount) as total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        return view('saas-admin.agents.index', [
            'agents' => $agents,
            'companyCounts' => $companyCounts,
            'earnedTotals' => $earnedTotals,
            'tableMissing' => false,
        ]);
    }

    public function store(Request $request)
    {
        $this->assertSuperAdmin();

        $request->validate([
            'name' => 'required|string|max:255',
            'cnic' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'email' => 'required|email|max:255|unique:agents,email',
            'territory' => 'nullable|string|max:255',
            'discount_percent' => 'nullable|numeric|min:0|max:' . \App\Services\DistributorPolicyService::policy()['max_discount'],
            'notes' => 'nullable|string|max:2000',
        ]);

        $agent = Agent::create($request->only([
            'name', 'cnic', 'phone', 'email', 'territory', 'discount_percent', 'notes',
        ]) + ['status' => 'active']);

        AdminAuditLog::log(auth('admin')->id(), 'Agent created', 'Agent', $agent->id, [
            'name' => $agent->name,
            'discount_percent' => (float) $agent->discount_percent,
        ]);

        return back()->with('success', "Agent '{$agent->name}' created.");
    }

    public function update(Request $request, $id)
    {
        $this->assertSuperAdmin();

        $agent = Agent::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'cnic' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'email' => ['required', 'email', 'max:255', Rule::unique('agents', 'email')->ignore($agent->id)],
            'territory' => 'nullable|string|max:255',
            'discount_percent' => 'nullable|numeric|min:0|max:' . \App\Services\DistributorPolicyService::policy()['max_discount'],
            'notes' => 'nullable|string|max:2000',
        ]);

        $oldRates = ['discount_percent' => (float) $agent->discount_percent];

        $attrs = $request->only([
            'name', 'cnic', 'phone', 'email', 'territory', 'discount_percent', 'notes',
        ]);
        $agent->update($attrs);

        AdminAuditLog::log(auth('admin')->id(), 'Agent updated', 'Agent', $agent->id, [
            'name' => $agent->name,
            'old_rates' => $oldRates,
            'new_rates' => ['discount_percent' => (float) $agent->discount_percent],
        ]);

        return back()->with('success', "Agent '{$agent->name}' updated. Rate changes apply to FUTURE commissions only.");
    }

    /** Toggle active ↔ terminated. Terminated agents earn no NEW commissions. */
    public function toggle($id)
    {
        $this->assertSuperAdmin();

        $agent = Agent::findOrFail($id);
        $newStatus = $agent->status === 'active' ? 'terminated' : 'active';

        // Record the FULL termination-window history so payments cleared while
        // terminated NEVER earn commission — even after any number of later
        // reactivate/terminate cycles and backfills.
        $windows = $agent->allTerminationWindows();
        if ($newStatus === 'terminated') {
            $windows[] = ['from' => now()->toDateTimeString(), 'to' => null];
            $agent->update([
                'status' => 'terminated',
                'terminated_at' => now(),
                'reactivated_at' => null,
                'termination_windows' => array_values($windows),
            ]);
        } else {
            // Close the open window (if any).
            for ($i = count($windows) - 1; $i >= 0; $i--) {
                if (($windows[$i]['to'] ?? null) === null) {
                    $windows[$i]['to'] = now()->toDateTimeString();
                    break;
                }
            }
            $agent->update([
                'status' => 'active',
                'reactivated_at' => now(),
                'termination_windows' => array_values($windows),
            ]);
        }

        AdminAuditLog::log(auth('admin')->id(), "Agent {$newStatus}", 'Agent', $agent->id, ['name' => $agent->name]);

        return back()->with('success', "Agent '{$agent->name}' is now {$newStatus}.");
    }

    public function show(Request $request, $id)
    {
        $this->assertSuperAdmin();

        $agent = Agent::findOrFail($id);

        // Safety net: backfill ledger lines for proofs verified before the
        // company was linked to this agent (or before the feature deployed).
        AgentCommissionService::syncForAgent($agent);

        $month = $this->parseMonth($request->get('month'));

        $companies = Company::withTrashed()
            ->where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'product_type', 'status', 'company_status', 'created_at', 'deleted_at']);

        $clearedPayments = PaymentProof::with(['company', 'pricingPlan'])
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('status', 'verified')
            ->orderByDesc('verified_at')
            ->limit(100)
            ->get();

        $lines = AgentCommission::with('company')
            ->where('agent_id', $agent->id)
            ->whereDate('period_month', $month->toDateString())
            ->orderBy('id')
            ->get();

        // Month dropdown options: every month present in the ledger + current.
        $months = AgentCommission::where('agent_id', $agent->id)
            ->selectRaw('period_month')
            ->distinct()
            ->orderByDesc('period_month')
            ->pluck('period_month')
            ->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('Y-m'))
            ->push(now()->format('Y-m'))
            ->unique()
            ->sortDesc()
            ->values();

        $totals = [
            'earned' => (float) $lines->whereIn('type', ['new', 'renewal'])->sum('amount'),
            'clawback' => (float) $lines->where('type', 'clawback')->sum('amount'),
            'net' => (float) $lines->sum('amount'),
            'lifetime' => (float) AgentCommission::where('agent_id', $agent->id)->sum('amount'),
        ];

        $awards = AgentIncentiveAward::where('agent_id', $agent->id)->latest('quarter')->get();
        $allDistributors = Agent::where('status', 'active')->orderBy('name')->get(['id','name']);
        return view('saas-admin.agents.show', compact(
            'agent', 'companies', 'clearedPayments', 'lines', 'month', 'months', 'totals', 'awards', 'allDistributors'
        ));
    }

    /** Correct attribution only before first verified subscription payment. */
    public function updateAttribution(Request $request, $id)
    {
        $this->assertSuperAdmin();
        $data = $request->validate(['agent_id' => ['nullable', 'integer', 'exists:agents,id'], 'reason' => ['required','string','max:500']]);
        $override = $request->boolean('super_admin_override');
        $adminId = auth('admin')->id();
        $result = DB::transaction(function () use ($id, $data, $override, $adminId) {
            // Same company-row lock used by payment approval closes the
            // attribution-vs-verification race in both directions.
            $company = Company::whereKey($id)->lockForUpdate()->firstOrFail();
            $locked = PaymentProof::subscriptionKind()
                ->where('company_id', $company->id)
                ->where('status', 'verified')
                ->exists();
            if ($locked && !$override) return false;

            $old = $company->agent_id;
            $company->update(['agent_id' => $data['agent_id'] ?: null]);
            AdminAuditLog::log($adminId, $locked ? 'Distributor attribution super-admin override' : 'Distributor attribution corrected', 'Company', $company->id, [
                'old_agent_id'=>$old,'new_agent_id'=>$company->agent_id,'reason'=>$data['reason'],'locked'=>$locked,
            ]);
            return true;
        }, 3);
        if (!$result) abort(422, 'Distributor attribution is locked after the first successful subscription payment.');
        return back()->with('success','Distributor attribution updated.');
    }

    public function createAward(Request $request, $id)
    {
        $this->assertSuperAdmin(); $agent=Agent::findOrFail($id);
        $data=$request->validate(['quarter'=>['required','regex:/^\d{4}-Q[1-4]$/']]);
        $award=DistributorIncentiveService::award($agent,$data['quarter']);
        if (!$award) return back()->with('error','No eligible award, or this quarterly award already exists.');
        if (!$award->wasRecentlyCreated) {
            return back()->with('error', 'This quarterly incentive award already exists (status: ' . $award->status . ').');
        }
        AdminAuditLog::log(auth('admin')->id(),'Distributor incentive awarded','AgentIncentiveAward',$award->id,['agent_id'=>$agent->id,'quarter'=>$award->quarter,'amount'=>(float)$award->amount]);
        return back()->with('success','Immutable quarterly incentive award created for approval.');
    }
    public function approveAward($id, $awardId)
    {
        $this->assertSuperAdmin();
        $award = AgentIncentiveAward::where('agent_id', $id)->findOrFail($awardId);
        $updated = AgentIncentiveAward::whereKey($award->id)->where('status', 'pending')->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by_admin_id' => auth('admin')->id(),
        ]);
        if (!$updated) return back()->with('error', 'This incentive was already processed.');
        AdminAuditLog::log(auth('admin')->id(), 'Distributor incentive approved', 'AgentIncentiveAward', $award->id, []);
        return back()->with('success','Incentive award approved.');
    }
    public function payAward($id, $awardId)
    {
        $this->assertSuperAdmin();
        $award = AgentIncentiveAward::where('agent_id', $id)->findOrFail($awardId);
        $updated = AgentIncentiveAward::whereKey($award->id)->where('status', 'approved')->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_by_admin_id' => auth('admin')->id(),
        ]);
        if (!$updated) return back()->with('error','Approve this incentive before marking it paid, or it was already processed.');
        AdminAuditLog::log(auth('admin')->id(),'Distributor incentive paid','AgentIncentiveAward',$award->id,[]);
        return back()->with('success','Incentive award marked paid.');
    }

    /** CSV export of the selected month's commission report. */
    public function export(Request $request, $id)
    {
        $this->assertSuperAdmin();

        $agent = Agent::findOrFail($id);
        $month = $this->parseMonth($request->get('month'));

        $lines = AgentCommission::where('agent_id', $agent->id)
            ->whereDate('period_month', $month->toDateString())
            ->orderBy('id')
            ->get();

        $filename = 'agent-' . $agent->id . '-commission-' . $month->format('Y-m') . '.csv';

        return response()->streamDownload(function () use ($agent, $month, $lines) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Agent', $agent->name]);
            fputcsv($out, ['Month', $month->format('F Y')]);
            fputcsv($out, []);
            fputcsv($out, ['Date', 'Company', 'Type', 'Base Amount (Rs)', 'Rate %', 'Commission (Rs)', 'Description']);
            foreach ($lines as $l) {
                fputcsv($out, [
                    optional($l->created_at)->format('Y-m-d'),
                    $l->company_name ?: optional($l->company)->name,
                    $l->type,
                    number_format((float) $l->base_amount, 2, '.', ''),
                    number_format((float) $l->rate_percent, 2, '.', ''),
                    number_format((float) $l->amount, 2, '.', ''),
                    $l->description,
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Earned', '', '', '', '', number_format((float) $lines->whereIn('type', ['new', 'renewal'])->sum('amount'), 2, '.', '')]);
            fputcsv($out, ['Clawback', '', '', '', '', number_format((float) $lines->where('type', 'clawback')->sum('amount'), 2, '.', '')]);
            fputcsv($out, ['Net Payable', '', '', '', '', number_format((float) $lines->sum('amount'), 2, '.', '')]);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Refund/reversal → clawback line. References an existing earn line and
     * writes a NEGATIVE adjustment into the chosen (default: current) month.
     */
    public function clawback(Request $request, $id)
    {
        $this->assertSuperAdmin();

        $agent = Agent::findOrFail($id);

        $request->validate([
            'commission_id' => 'required|integer|exists:agent_commissions,id',
            'amount' => 'nullable|numeric|min:0.01|max:100000000',
            'reason' => 'required|string|max:500',
        ]);

        $line = DB::transaction(function () use ($agent, $request) {
            // Serialize every refund adjustment for this earned decision. The
            // clawback sum must be read only after this row lock is acquired.
            $original = AgentCommission::where('agent_id', $agent->id)
                ->whereIn('type', ['new', 'renewal'])
                ->lockForUpdate()
                ->findOrFail((int) $request->commission_id);

            $alreadyClawed = (float) AgentCommission::where('agent_id', $agent->id)
                ->where('type', 'clawback')
                ->where('payment_proof_id', $original->payment_proof_id)
                ->sum('amount'); // negative or 0
            $remaining = round((float) $original->amount + $alreadyClawed, 2);
            if ($remaining <= 0) {
                return null;
            }

            $amount = $request->filled('amount') ? min((float) $request->amount, $remaining) : $remaining;
            $line = AgentCommission::create([
                'agent_id' => $agent->id,
                'company_id' => $original->company_id,
                'company_name' => $original->company_name,
                'payment_proof_id' => $original->payment_proof_id,
                'type' => 'clawback',
                'base_amount' => $original->base_amount,
                'rate_percent' => $original->rate_percent,
                'amount' => -round($amount, 2),
                'period_month' => now()->startOfMonth()->toDateString(),
                'description' => 'Clawback (refund/reversal): ' . $request->reason,
                'created_by_admin_id' => auth('admin')->id(),
            ]);

            AdminAuditLog::log(auth('admin')->id(), 'Agent commission clawback', 'AgentCommission', $line->id, [
                'agent_id' => $agent->id,
                'original_commission_id' => $original->id,
                'amount' => -round($amount, 2),
                'reason' => $request->reason,
            ]);

            return $line;
        }, 3);
        if (!$line) {
            return back()->with('error', 'This commission line is already fully clawed back.');
        }

        return redirect()->route('saas.admin.agents.show', ['id' => $agent->id, 'month' => now()->format('Y-m')])
            ->with('success', 'Clawback line recorded — commission adjusted by Rs ' . number_format(abs((float) $line->amount), 2) . '.');
    }

    public function markPaid($id, $commissionId)
    {
        $this->assertSuperAdmin();
        $agent = Agent::findOrFail($id);
        $line = AgentCommission::where('agent_id', $agent->id)
            ->whereIn('type', ['new', 'renewal'])
            ->findOrFail($commissionId);

        if ($line->status !== 'paid') {
            if ($line->hold_until && $line->hold_until->isFuture()) {
                return back()->with('error', 'This commission is on hold until ' . $line->hold_until->format('d M Y') . '.');
            }
            $updated = AgentCommission::whereKey($line->id)->where('status', '!=', 'paid')
                ->where(function ($q) { $q->whereNull('hold_until')->orWhere('hold_until', '<=', now()); })->update([
                'status' => 'paid',
                'paid_at' => now(),
                'paid_by_admin_id' => auth('admin')->id(),
            ]);
            if (!$updated) return back()->with('error', 'This commission is still on hold or was already processed.');
            AdminAuditLog::log(auth('admin')->id(), 'Agent commission marked paid', 'AgentCommission', $line->id, [
                'agent_id' => $agent->id,
                'amount' => (float) $line->amount,
            ]);
        }

        return back()->with('success', 'Commission marked paid.');
    }

    private function parseMonth(?string $raw): \Illuminate\Support\Carbon
    {
        try {
            return $raw
                ? \Illuminate\Support\Carbon::createFromFormat('Y-m', $raw)->startOfMonth()
                : now()->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }
}
