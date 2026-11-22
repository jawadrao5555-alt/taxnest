<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUsageStat;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminUsageController extends Controller
{
    public function index(?Request $request = null)
    {
        $request ??= request();
        $customDates = ($request->input('period') ?? 'custom') === 'custom';
        $filters = $request->validate([
            'export' => 'nullable|in:local_csv',
            'company_id' => 'required_if:export,local_csv|nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:0',
            'period' => 'nullable|in:custom,daily,monthly,yearly,two_year',
            'as_of' => 'nullable|date_format:Y-m-d',
            ...($customDates ? [
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to' => 'nullable|date_format:Y-m-d',
            ] : []),
        ]);
        if (isset($filters['branch_id']) && !isset($filters['company_id'])) {
            throw ValidationException::withMessages(['branch_id' => 'Choose a company before applying a campus filter.']);
        }

        $today = today()->toImmutable();
        $cutoff = ($filters['export'] ?? null) === 'local_csv' && isset($filters['as_of'])
            ? CarbonImmutable::parse($filters['as_of'])
            : $today;
        if ($customDates && isset($filters['date_from']) && $filters['date_from'] > ($filters['date_to'] ?? $today->toDateString())) {
            throw ValidationException::withMessages(['date_from' => 'Start date is after the reporting end date.']);
        }
        $period = $filters['period'] ?? 'custom';
        $from = match ($period) {
            'daily' => $cutoff,
            'monthly' => $cutoff->startOfMonth(),
            'yearly', 'two_year' => $cutoff->startOfYear(),
            default => null,
        };
        if ($from) {
            $filters['date_from'] = $from->toDateString();
            $filters['date_to'] = $cutoff->toDateString();
        }
        if ($period === 'two_year') {
            $lastYear = $cutoff->subYearNoOverflow();
            $filters['comparison'] = [
                'date_from' => $lastYear->startOfYear()->toDateString(),
                'date_to' => $lastYear->toDateString(),
            ];
        }
        $companies = Company::when(isset($filters['company_id']), fn($q)=>$q->whereKey($filters['company_id']))->orderBy('name')->orderBy('id')->paginate(50)->withQueryString();
        $usageStats = CompanyUsageStat::snapshotsForCompanies($companies->getCollection(), $filters);

        if (($filters['export'] ?? null) === 'local_csv') {
            $snapshot = $usageStats->first();
            abort_unless($snapshot?->local_collections, 404);
            return response()->streamDownload(function () use ($snapshot) {
                $stream = fopen('php://output', 'wb');
                fputcsv($stream, ['company_id', 'campus_id', 'from', 'to', 'coverage', 'principal_paisa', 'received_paisa', 'outstanding_paisa', 'closing_cash_paisa'], ',', '"', '');
                foreach ([$snapshot->local_collections, $snapshot->local_collections_comparison] as $local) {
                    if ($local === null) continue;
                    fputcsv($stream, [
                        $local['company']['id'], $local['filters']['branch_id'] ?? 'all',
                        $local['period']['from'], $local['period']['to'], $local['coverage']['status'],
                        $local['billing']['principal_paisa'] ?? null, $local['payments']['collected_paisa'] ?? null,
                        $local['balances']['outstanding_paisa'] ?? null, $local['closings']['cash_paisa'] ?? null,
                    ], ',', '"', '');
                }
                fclose($stream);
            }, 'local-collections-'.$snapshot->company_id.'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
        }

        return view('saas-admin.company-usage', compact('usageStats', 'companies', 'filters'));
    }
}
