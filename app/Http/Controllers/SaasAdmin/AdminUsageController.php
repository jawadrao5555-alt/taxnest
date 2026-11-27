<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUsageStat;
use App\Services\Reporting\ErpAdminFinancialSummary;
use App\Support\NestErps;
use App\Support\ProductCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUsageController extends Controller
{
    public function index(?Request $request = null)
    {
        $request ??= request();
        $productScopes = [];
        foreach (ProductCatalog::TYPES as $type) {
            $productScopes[$type] = ProductCatalog::label($type);
        }
        $productScopes += ['school' => 'School POS', 'hospital' => 'Hospital POS'];
        $customDates = ($request->input('period') ?? 'custom') === 'custom';
        $filters = $request->validate([
            'export' => 'nullable|in:local_csv,pra_csv,fbr_csv,di_csv',
            'company_id' => 'required_if:export,local_csv,pra_csv,fbr_csv,di_csv|nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:0',
            'product_scope' => ['nullable', Rule::in(array_keys($productScopes))],
            'period' => 'nullable|in:custom,daily,monthly,yearly,two_year',
            'as_of' => 'nullable|date_format:Y-m-d',
            ...($customDates ? [
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to' => 'nullable|date_format:Y-m-d',
            ] : []),
        ]);
        if (isset($filters['branch_id']) && !isset($filters['company_id'])) {
            throw ValidationException::withMessages(['branch_id' => 'Choose a company before applying a branch or campus filter.']);
        }

        $today = today()->toImmutable();
        $cutoff = isset($filters['export'], $filters['as_of'])
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
        $companyQuery = Company::query()
            ->when(isset($filters['company_id']), fn ($query) => $query->whereKey($filters['company_id']));
        $scope = $filters['product_scope'] ?? null;
        if (isset($scope)) {
            $categories = match ($scope) {
                'school' => ['education'],
                'hospital' => ['clinic', 'hospital'],
                default => [],
            };
            $companyQuery->whereIn('product_type', NestErps::storedTypesFor(
                $categories === [] ? $scope : ProductCatalog::FBRPOS
            ));
            if ($categories !== []) {
                $categoryColumns = array_filter(
                    ['pos_type', 'business_category'],
                    fn ($column) => Schema::hasColumn('companies', $column)
                );
                $companyQuery->where(function ($query) use ($categories, $categoryColumns) {
                    $query->whereRaw('1 = 0');
                    foreach ($categoryColumns as $column) {
                        $query->orWhereIn($column, $categories);
                    }
                });
                if ($scope === 'hospital') {
                    // Collection reports already give the School ledger priority for legacy mixed-category rows.
                    foreach ($categoryColumns as $column) {
                        $companyQuery->where(fn ($query) => $query->whereNull($column)->orWhere($column, '!=', 'education'));
                    }
                }
            }
        }
        $companies = $companyQuery->orderBy('name')->orderBy('id')->paginate(50)->withQueryString();
        $usageStats = CompanyUsageStat::snapshotsForCompanies($companies->getCollection(), $filters);

        if (in_array($filters['export'] ?? null, ['pra_csv', 'fbr_csv', 'di_csv'], true)) {
            $snapshot = $usageStats->first();
            abort_if($snapshot === null, 404);
            [$totals, $comparison, $filename, $headers] = match ($filters['export']) {
                'di_csv' => [
                    $snapshot->di_documents, $snapshot->di_comparison, 'di-production-'.$snapshot->company_id.'.csv',
                    ['company_id', 'branch_id', 'invoice_date_from', 'invoice_date_to', 'reported_documents', 'net_document_value_pkr'],
                ],
                'fbr_csv' => [$snapshot->fbr_sales, $snapshot->fbr_comparison, 'fbr-bills-'.$snapshot->company_id.'.csv', null],
                'pra_csv' => [
                    $snapshot->total_pos_transactions === null ? null : [
                        'bills' => $snapshot->total_pos_transactions, 'amount' => $snapshot->total_sales_amount,
                    ],
                    $snapshot->pra_comparison, 'pra-bills-'.$snapshot->company_id.'.csv', null,
                ],
            };
            abort_if($totals === null, 404);
            $headers ??= ['company_id', 'branch_id', 'business_date_from', 'business_date_to', 'completed_bills', 'total_pkr'];
            $branch = $filters['branch_id'] ?? 'all';
            $rows = [[
                $snapshot->company_id, $branch, $filters['date_from'] ?? null, $filters['date_to'] ?? null,
                $totals['bills'], number_format($totals['amount'], 2, '.', ''),
            ]];
            if ($comparison !== null) {
                $rows[] = [
                    $snapshot->company_id, $branch, $filters['comparison']['date_from'], $filters['comparison']['date_to'],
                    $comparison['bills'], number_format($comparison['amount'], 2, '.', ''),
                ];
            }

            return response()->streamDownload(function () use ($rows, $headers) {
                $stream = fopen('php://output', 'wb');
                fputcsv($stream, $headers, ',', '"', '');
                foreach ($rows as $row) {
                    fputcsv($stream, $row, ',', '"', '');
                }
                fclose($stream);
            }, $filename, ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
        }

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

        $financials = app(ErpAdminFinancialSummary::class);
        $erpFinancials = $financials->forCompanies($companies->getCollection(), $filters);
        $erpComparison = isset($filters['comparison'])
            ? $financials->forCompanies($companies->getCollection(), $filters['comparison'] + $filters)
            : collect();

        return view('saas-admin.company-usage', compact(
            'usageStats', 'companies', 'filters', 'productScopes', 'erpFinancials', 'erpComparison'
        ));
    }
}
