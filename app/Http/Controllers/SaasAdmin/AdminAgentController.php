<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Services\AgentCommissionService;
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
            'password' => 'required|string|min:8',
            'territory' => 'nullable|string|max:255',
            'rate_new' => 'required|numeric|min:0|max:100',
            'rate_renewal' => 'required|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:2000',
        ]);

        $agent = Agent::create($request->only([
            'name', 'cnic', 'phone', 'email', 'password', 'territory',
            'rate_new', 'rate_renewal', 'notes',
        ]) + ['status' => 'active']);

        AdminAuditLog::log(auth('admin')->id(), 'Agent created', 'Agent', $agent->id, [
            'name' => $agent->name,
            'rate_new' => (float) $agent->rate_new,
            'rate_renewal' => (float) $agent->rate_renewal,
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
            'password' => 'nullable|string|min:8',
            'territory' => 'nullable|string|max:255',
            'rate_new' => 'required|numeric|min:0|max:100',
            'rate_renewal' => 'required|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:2000',
        ]);

        $oldRates = ['new' => (float) $agent->rate_new, 'renewal' => (float) $agent->rate_renewal];

        $attrs = $request->only([
            'name', 'cnic', 'phone', 'email', 'territory',
            'rate_new', 'rate_renewal', 'notes',
        ]);
        if ($request->filled('password')) {
            $attrs['password'] = $request->password;
        }
        $agent->update($attrs);

        AdminAuditLog::log(auth('admin')->id(), 'Agent updated', 'Agent', $agent->id, [
            'name' => $agent->name,
            'old_rates' => $oldRates,
            'new_rates' => ['new' => (float) $agent->rate_new, 'renewal' => (float) $agent->rate_renewal],
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

        return view('saas-admin.agents.show', compact(
            'agent', 'companies', 'clearedPayments', 'lines', 'month', 'months', 'totals'
        ));
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

        $original = AgentCommission::where('agent_id', $agent->id)
            ->whereIn('type', ['new', 'renewal'])
            ->findOrFail((int) $request->commission_id);

        // Cap: total clawback against a line can never exceed what was earned.
        $alreadyClawed = (float) AgentCommission::where('agent_id', $agent->id)
            ->where('type', 'clawback')
            ->where('payment_proof_id', $original->payment_proof_id)
            ->sum('amount'); // negative or 0
        $remaining = round((float) $original->amount + $alreadyClawed, 2);
        if ($remaining <= 0) {
            return back()->with('error', 'This commission line is already fully clawed back.');
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

        return redirect()->route('saas.admin.agents.show', ['id' => $agent->id, 'month' => now()->format('Y-m')])
            ->with('success', 'Clawback line recorded — commission adjusted by Rs ' . number_format($amount, 2) . '.');
    }

    public function markPaid($id, $commissionId)
    {
        $this->assertSuperAdmin();
        $agent = Agent::findOrFail($id);
        $line = AgentCommission::where('agent_id', $agent->id)
            ->whereIn('type', ['new', 'renewal'])
            ->findOrFail($commissionId);

        if ($line->status !== 'paid') {
            $line->update([
                'status' => 'paid',
                'paid_at' => now(),
                'paid_by_admin_id' => auth('admin')->id(),
            ]);
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
