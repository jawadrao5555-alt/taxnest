<?php

namespace App\Http\Controllers\Health;

use App\Models\Branch;
use App\Services\HealthAudit\HealthAuditRecorder;
use App\Services\HealthLabOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HealthLabOperationsController extends HealthPanelController
{
    private const METRICS = ['orders', 'ready_orders', 'open_orders', 'ordered_tests', 'verified_tests', 'rejected_specimens'];

    private const COST_METRICS = ['known_cost_paisa', 'costed_tests', 'uncosted_tests'];

    public function index(Request $request, HealthLabOperations $operations)
    {
        $report = $this->report($request, $operations);

        return response()->view('health.lab.operations', $report)
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, HealthLabOperations $operations)
    {
        $report = $this->report($request, $operations);
        HealthAuditRecorder::record('lab.operations_exported', [
            'actor' => $this->user(),
            'company_id' => $this->user()->company_id,
            'branch_id' => $report['branchId'],
            'category' => 'export',
            'action' => 'exported',
            'meta' => ['from' => $report['from'], 'through' => $report['through']],
        ]);

        return response()->streamDownload(function () use ($report) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['From', 'Through', 'Branch ID', 'Branch', 'Orders', 'Ready orders', 'Open orders',
                'Ordered tests', 'Verified tests', 'Rejected sample attempts'], escape: '');
            foreach ($report['rows'] as $row) {
                // Branch names are customer-entered. Keep formulas inert in a spreadsheet.
                $name = preg_match('/^[\s\x{FEFF}]*[=+@-]/u', $row->branch_name) ? "'".$row->branch_name : $row->branch_name;
                fputcsv($stream, [$report['from'], $report['through'], $row->branch_id, $name,
                    ...array_map(fn ($metric) => (int) $row->{$metric}, self::METRICS)], escape: '');
            }
            fclose($stream);
        }, "laboratory-operations-{$report['from']}-{$report['through']}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function costs(Request $request, HealthLabOperations $operations)
    {
        return response()->view('health.lab.operations', $this->report($request, $operations, true))
            ->header('Cache-Control', 'private, no-store');
    }

    private function report(Request $request, HealthLabOperations $operations, bool $costing = false): array
    {
        $data = $request->validate([
            'from' => ['required_with:through', 'date_format:Y-m-d'],
            'through' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $today = CarbonImmutable::today();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $today->subDays(29);
        $through = isset($data['through']) ? CarbonImmutable::parse($data['through']) : $today;
        if ($from->diffInDays($through) > 92) {
            throw ValidationException::withMessages(['through' => __('lab_operations.date_range')]);
        }
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $this->requireBranch($branchId);

        $boundary = $this->branchBoundary();
        $branches = Branch::query()->where('company_id', $this->user()->company_id)
            ->when($boundary !== null, fn ($query) => $query->whereIn('id', $boundary))
            ->orderBy('name')->pluck('name', 'id');

        $rows = $costing
            ? $operations->costsByBranch($this->user(), $from, $through, $branchId)
            : $operations->byBranch($this->user(), $from, $through, $branchId);
        $rows = $rows->each(function ($row) use ($branches) {
            $row->branch_name = $row->branch_id === null
                ? __('lab_operations.unassigned')
                : $branches->get($row->branch_id, __('lab_operations.unknown_branch'));
        })->sortBy('branch_name')->values();

        return [
            'from' => $from->toDateString(),
            'through' => $through->toDateString(),
            'branchId' => $branchId,
            'branches' => $branches,
            'rows' => $rows,
            'metrics' => $costing ? self::COST_METRICS : self::METRICS,
            'costing' => $costing,
        ];
    }
}
