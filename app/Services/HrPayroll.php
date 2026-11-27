<?php

namespace App\Services;

use App\Models\HrPayrollLine;
use App\Models\HrPayrollPeriod;
use App\Support\Hr\PayrollRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HrPayroll
{
    public function __construct(private HrWorkspaceAccess $access)
    {
    }

    public function create(int $organizationId, int $actorId, array $input): HrPayrollPeriod
    {
        $this->access->membership($organizationId, $actorId, 'payroll.write');
        $data = validator($input, ['month' => ['required', 'date_format:Y-m', 'regex:/^[1-9][0-9]{3}-/']])->validate();

        return DB::transaction(function () use ($organizationId, $actorId, $data) {
            $workspace = $this->access->membership($organizationId, $actorId, 'payroll.write', lock: true)->organization;
            $month = $data['month'].'-01';

            return $workspace->payrollPeriods()->whereDate('month', $month)->first()
                ?? $workspace->payrollPeriods()->create(['month' => $month, 'created_by' => $actorId]);
        }, 3);
    }

    public function saveLine(int $organizationId, int $actorId, int $periodId, array $input): void
    {
        $this->access->membership($organizationId, $actorId, 'payroll.write');
        $data = validator($input, [
            'version' => ['required', 'integer', 'min:1'],
            'employee_id' => ['required', 'integer', 'min:1'],
            ...array_fill_keys(array_keys(HrPayrollLine::INPUTS), ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999.99']),
        ])->validate();

        $inputs = array_intersect_key($data, HrPayrollLine::INPUTS);
        $net = PayrollRules::net($inputs['basic'], $inputs['allowances'], $inputs['overtime'], $inputs['deductions'], $inputs['bonus']);
        if ($net < 0) {
            throw ValidationException::withMessages(['deductions' => 'Deductions cannot exceed earnings.']);
        }
        $minor = [];
        foreach ($inputs as $field => $amount) {
            $minor[$field.'_minor'] = (int) round($amount * 100);
        }

        DB::transaction(function () use ($organizationId, $actorId, $periodId, $data, $minor, $net) {
            $workspace = $this->access->membership($organizationId, $actorId, 'payroll.write', lock: true)->organization;
            $period = $workspace->payrollPeriods()->lockForUpdate()->findOrFail($periodId);
            $this->requireDraftVersion($period, (int) $data['version']);
            $employee = $workspace->employees()->lockForUpdate()->findOrFail($data['employee_id']);
            $period->lines()->updateOrCreate(['employee_id' => $employee->id], [
                ...$minor,
                'employee_user_id' => $employee->user_id, 'employee_number' => $employee->employee_number,
                'employee_name' => $employee->full_name,
                'net_minor' => (int) round($net * 100), 'prepared_by' => $actorId,
            ]);
            $period->increment('version');
        }, 3);
    }

    public function removeLine(int $organizationId, int $actorId, int $periodId, int $lineId, array $input): void
    {
        $this->access->membership($organizationId, $actorId, 'payroll.write');
        $data = validator($input, ['version' => ['required', 'integer', 'min:1']])->validate();

        DB::transaction(function () use ($organizationId, $actorId, $periodId, $lineId, $data) {
            $workspace = $this->access->membership($organizationId, $actorId, 'payroll.write', lock: true)->organization;
            $period = $workspace->payrollPeriods()->lockForUpdate()->findOrFail($periodId);
            $this->requireDraftVersion($period, (int) $data['version']);
            $period->lines()->findOrFail($lineId)->delete();
            $period->increment('version');
        }, 3);
    }

    public function approve(int $organizationId, int $actorId, int $periodId, array $input): void
    {
        $this->access->membership($organizationId, $actorId, 'payroll.approve');
        $data = validator($input, ['version' => ['required', 'integer', 'min:1']])->validate();

        DB::transaction(function () use ($organizationId, $actorId, $periodId, $data) {
            $workspace = $this->access->membership($organizationId, $actorId, 'payroll.approve', lock: true)->organization;
            $period = $workspace->payrollPeriods()->lockForUpdate()->findOrFail($periodId);
            $version = (int) $data['version'];
            if ($period->isApproved() && (int) $period->approved_by === $actorId && $period->version === $version) {
                return;
            }
            $this->requireDraftVersion($period, $version);
            if (!$period->lines()->exists()) {
                throw ValidationException::withMessages(['version' => 'Add an employee payroll line before approving.']);
            }
            $period->update(['approved_at_utc' => now('UTC')->toDateTimeString(), 'approved_by' => $actorId]);
        }, 3);
    }

    private function requireDraftVersion(HrPayrollPeriod $period, int $version): void
    {
        if ($period->isApproved()) {
            throw ValidationException::withMessages(['version' => 'Approved payroll is locked.']);
        }
        if ($period->version !== $version) {
            throw ValidationException::withMessages(['version' => 'Payroll changed. Reload the latest draft before continuing.']);
        }
    }
}
