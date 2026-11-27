<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrPayrollLine;
use App\Services\HrPayroll;
use App\Services\HrWorkspaceAccess;
use App\Support\Hr\TenantAccess;
use Illuminate\Http\Request;

final class HrPayrollController extends Controller
{
    public function __construct(private HrWorkspaceAccess $access, private HrPayroll $payroll)
    {
    }

    public function index(int $organization)
    {
        $membership = $this->access->membership($organization, (int) auth('hr')->id(), 'payroll.read');
        $workspace = $membership->organization;
        $canEdit = TenantAccess::allows($organization, $membership->organization_id, $membership->role, 'payroll.write');
        $periods = $workspace->payrollPeriods()->withCount('lines')->orderByDesc('month')->paginate(25);

        return view('hr.payroll-index', compact('workspace', 'canEdit', 'periods'));
    }

    public function show(int $organization, int $period)
    {
        $membership = $this->access->membership($organization, (int) auth('hr')->id(), 'payroll.read');
        $workspace = $membership->organization;
        $period = $workspace->payrollPeriods()->findOrFail($period);
        $canEdit = !$period->isApproved() && TenantAccess::allows($organization, $membership->organization_id, $membership->role, 'payroll.write');
        $canApprove = !$period->isApproved() && TenantAccess::allows($organization, $membership->organization_id, $membership->role, 'payroll.approve');
        $lines = $period->lines()->orderBy('employee_number')->get();
        $employees = $canEdit ? $workspace->employees()->orderBy('full_name')->get(['id', 'employee_number', 'full_name', 'status']) : collect();

        return view('hr.payroll-period', compact('workspace', 'period', 'lines', 'employees', 'canEdit', 'canApprove'));
    }

    public function store(Request $request, int $organization)
    {
        $period = $this->payroll->create($organization, (int) auth('hr')->id(), $request->all());

        return redirect()->route('hr.payroll.show', ['organization' => $organization, 'period' => $period->id]);
    }

    public function saveLine(Request $request, int $organization, int $period)
    {
        $this->payroll->saveLine($organization, (int) auth('hr')->id(), $period, $request->all());

        return redirect()->route('hr.payroll.show', compact('organization', 'period'))->with('status', 'Payroll line saved.');
    }

    public function removeLine(Request $request, int $organization, int $period, int $line)
    {
        $this->payroll->removeLine($organization, (int) auth('hr')->id(), $period, $line, $request->all());

        return redirect()->route('hr.payroll.show', compact('organization', 'period'))->with('status', 'Draft payroll line removed.');
    }

    public function approve(Request $request, int $organization, int $period)
    {
        $this->payroll->approve($organization, (int) auth('hr')->id(), $period, $request->all());

        return redirect()->route('hr.payroll.show', compact('organization', 'period'))->with('status', 'Payroll approved and locked.');
    }

    public function payslip(int $organization, int $period, int $line)
    {
        $workspace = $this->access->membership($organization, (int) auth('hr')->id(), 'payroll.read')->organization;
        $period = $workspace->payrollPeriods()->whereNotNull('approved_at_utc')->findOrFail($period);
        $line = $period->lines()->findOrFail($line);

        return view('hr.payroll-payslip', compact('workspace', 'period', 'line') + ['selfService' => false]);
    }

    public function ownPayslips(int $organization)
    {
        $actor = (int) auth('hr')->id();
        $workspace = $this->access->membership($organization, $actor, 'self.read')->organization;
        $payslips = HrPayrollLine::query()->where('employee_user_id', $actor)->whereHas('period', fn ($query) =>
            $query->where('organization_id', $organization)->whereNotNull('approved_at_utc'))
            ->with('period:id,month')->latest('id')->paginate(25);

        return view('hr.payroll-self', compact('workspace', 'payslips'));
    }

    public function ownPayslip(int $organization, int $period, int $line)
    {
        $actor = (int) auth('hr')->id();
        $workspace = $this->access->membership($organization, $actor, 'self.read')->organization;
        $period = $workspace->payrollPeriods()->whereNotNull('approved_at_utc')->findOrFail($period);
        $line = $period->lines()->where('employee_user_id', $actor)->findOrFail($line);

        return view('hr.payroll-payslip', compact('workspace', 'period', 'line') + ['selfService' => true]);
    }

    public function export(int $organization, int $period)
    {
        $workspace = $this->access->membership($organization, (int) auth('hr')->id(), 'payroll.read')->organization;
        $period = $workspace->payrollPeriods()->whereNotNull('approved_at_utc')->findOrFail($period);
        $filename = 'payroll-'.$period->month->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($period) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Employee number', 'Employee name', ...array_values(HrPayrollLine::INPUTS), 'Net pay', 'Currency'], escape: '');
            foreach ($period->lines()->lazyById() as $line) {
                $amounts = array_map(fn ($field) => HrPayrollLine::amount($line->{$field.'_minor'}), array_keys(HrPayrollLine::INPUTS));
                fputcsv($output, [
                    $this->csvText($line->employee_number), $this->csvText($line->employee_name),
                    ...$amounts, HrPayrollLine::amount($line->net_minor), 'PKR',
                ], escape: '');
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvText(string $value): string
    {
        return preg_match('/^\s*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value;
    }
}
