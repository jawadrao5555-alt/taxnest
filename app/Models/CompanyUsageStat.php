<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class CompanyUsageStat extends Model
{
    protected $fillable = [
        'company_id', 'total_pos_transactions', 'total_sales_amount',
        'active_terminals', 'active_users', 'inventory_items', 'last_activity_at',
    ];

    protected $casts = [
        'total_sales_amount' => 'float',
        'last_activity_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public static function refreshForCompany(int $companyId): self
    {
        // Compatibility entrypoint for the company detail screen. Reading
        // usage must never create activity or write a cached statistics row.
        return self::snapshotsForCompanies(collect([
            Company::withTrashed()->findOrFail($companyId),
        ]))->first();
    }

    /** Batched, read-only snapshots for the visible companies, including real zeroes. */
    public static function snapshotsForCompanies(Collection $companies, array $localFilters = []): Collection
    {
        if ($companies->isEmpty()) {
            return collect();
        }

        $ids = $companies->pluck('id');
        $praIds = $companies->filter(fn ($company) => $company->product_type === 'pos')->pluck('id');
        $fbrIds = $companies->filter(fn ($company) => $company->product_type === 'fbrpos'
            && !$company->isLocalCollectionPilot())->pluck('id');
        $diIds = $companies->where('product_type', 'di')->pluck('id');
        $praTable = 'pos_transactions';
        $fbrTable = 'fbr_pos_transactions';
        $sales = self::completedSales($praTable, $praIds, $localFilters);
        $fbrSales = self::completedSales($fbrTable, $fbrIds, $localFilters);
        $diDocuments = self::reportedDiDocuments($diIds, $localFilters);
        $comparisonFilters = isset($localFilters['comparison']) ? $localFilters['comparison'] + $localFilters : null;
        $comparison = $comparisonFilters ? self::completedSales($praTable, $praIds, $comparisonFilters) : null;
        $fbrComparison = $comparisonFilters ? self::completedSales($fbrTable, $fbrIds, $comparisonFilters) : null;

        $diComparison = $comparisonFilters ? self::reportedDiDocuments($diIds, $comparisonFilters) : null;

        $counts = [];
        foreach (['active_terminals' => 'pos_terminals', 'active_users' => 'users', 'inventory_items' => 'inventory_stocks'] as $field => $table) {
            $columns = $field === 'active_terminals' ? ['company_id', 'is_active'] : ['company_id'];
            $counts[$field] = Schema::hasColumns($table, $columns)
                ? DB::table($table)->whereIn('company_id', $field === 'active_users' ? $ids : $praIds)
                    ->when($field === 'active_terminals', fn ($query) => $query->where('is_active', true))
                    ->selectRaw('company_id, COUNT(*) as total')->groupBy('company_id')
                    ->pluck('total', 'company_id')
                : null;
        }

        $lastActivity = [];
        foreach (['users' => 'last_login_at', 'pos_user_sessions' => 'last_activity_at'] as $table => $column) {
            if (!Schema::hasColumns($table, ['company_id', $column])) {
                continue;
            }
            $recorded = DB::table($table)->whereIn('company_id', $ids)
                ->selectRaw("company_id, MAX({$column}) as recorded_at")
                ->groupBy('company_id')->get();
            foreach ($recorded as $row) {
                if ($row->recorded_at && (!isset($lastActivity[$row->company_id])
                    || Carbon::parse($row->recorded_at)->gt(Carbon::parse($lastActivity[$row->company_id])))) {
                    $lastActivity[$row->company_id] = $row->recorded_at;
                }
            }
        }

        $localReporter=app(\App\Services\Reporting\LocalCollectionReports::class);
        return $companies->map(function (Company $company) use ($sales, $comparison, $fbrSales, $fbrComparison, $diDocuments, $diComparison, $counts, $lastActivity, $localReporter, $localFilters) {
            $pra = $company->product_type === 'pos';
            $sale = $sales?->get($company->id);
            $snapshot = new self([
                'company_id' => $company->id,
                'total_pos_transactions' => $pra && $sales !== null ? (int) ($sale->bills ?? 0) : null,
                'total_sales_amount' => $pra && $sales !== null ? (float) ($sale->amount ?? 0) : null,
                'last_activity_at' => $lastActivity[$company->id] ?? null,
            ]);
            $snapshot->setAttribute('pra_comparison', $pra ? self::salesTotals($comparison, $company->id) : null);
            $fbr = $company->product_type === 'fbrpos' && !$company->isLocalCollectionPilot();
            $snapshot->setAttribute('fbr_sales', $fbr ? self::salesTotals($fbrSales, $company->id) : null);
            $snapshot->setAttribute('fbr_comparison', $fbr ? self::salesTotals($fbrComparison, $company->id) : null);
            $di = $company->product_type === 'di';
            $snapshot->setAttribute('di_documents', $di ? self::salesTotals($diDocuments, $company->id) : null);
            $snapshot->setAttribute('di_comparison', $di ? self::salesTotals($diComparison, $company->id) : null);
            foreach ($counts as $field => $values) {
                $snapshot->setAttribute($field, $values !== null && ($pra || $field === 'active_users')
                    ? (int) ($values[$company->id] ?? 0) : null);
            }
            $snapshot->setRelation('company', $company);
            $snapshot->setAttribute('local_collections', $company->isLocalCollectionPilot() ? $localReporter->read($company, $localFilters) : null);
            if ($company->isLocalCollectionPilot() && isset($localFilters['comparison'])) {
                $snapshot->setAttribute('local_collections_comparison', $localReporter->read(
                    $company,
                    $localFilters['comparison'] + $localFilters
                ));
            }

            return $snapshot;
        });
    }

    private static function completedSales(string $table, Collection $companyIds, array $filters): ?Collection
    {
        if ($companyIds->isEmpty() || !Schema::hasColumns($table, ['company_id', 'status', 'total_amount'])) {
            return null;
        }
        $sales = DB::table($table)->whereIn('company_id', $companyIds)->where('status', 'completed')
            ->when(Schema::hasColumn($table, 'is_archived'), fn (Builder $query) => $query
                ->where(fn (Builder $visible) => $visible->where('is_archived', false)->orWhereNull('is_archived')))
            ->selectRaw('company_id, COUNT(*) as bills, COALESCE(SUM(total_amount), 0) as amount')
            ->groupBy('company_id');
        return self::scopedTotals($sales, $table, 'business_date', $filters);
    }

    private static function reportedDiDocuments(Collection $companyIds, array $filters): ?Collection
    {
        $table = 'invoices';
        if ($companyIds->isEmpty() || !Schema::hasColumns($table, [
            'company_id', 'status', 'fbr_status', 'fbr_invoice_number', 'document_type', 'total_amount',
        ])) {
            return null;
        }

        $documents = DB::table($table)->whereIn('company_id', $companyIds)
            ->where('status', 'locked')
            ->where('fbr_status', 'production')
            ->whereNotNull('fbr_invoice_number')
            ->whereRaw("TRIM(fbr_invoice_number) <> ''");
        if (Schema::hasColumn($table, 'fiscal_submission_environment')) {
            $documents->where(fn (Builder $query) => $query
                ->whereNull('fiscal_submission_environment')->orWhere('fiscal_submission_environment', 'production'));
        }
        $documents->selectRaw(
            'company_id, COUNT(*) as bills, SUM(CASE WHEN document_type = ? THEN -total_amount ELSE total_amount END) as amount',
            ['Credit Note']
        )->groupBy('company_id');

        return self::scopedTotals($documents, $table, 'invoice_date', $filters);
    }

    private static function scopedTotals(Builder $query, string $table, string $dateColumn, array $filters): ?Collection
    {
        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            if (!Schema::hasColumn($table, $dateColumn)) {
                return null;
            }
            $query->when(isset($filters['date_from']), fn (Builder $range) => $range->where($dateColumn, '>=', $filters['date_from']))
                ->when(isset($filters['date_to']), fn (Builder $range) => $range->where($dateColumn, '<=', $filters['date_to']));
        }
        if (isset($filters['branch_id'])) {
            if (!Schema::hasColumn($table, 'branch_id')) {
                return null;
            }
            $branchId = (int) $filters['branch_id'];
            $query->where(fn (Builder $branch) => $branch->where('branch_id', $branchId)
                ->when($branchId === 0, fn (Builder $unassigned) => $unassigned->orWhereNull('branch_id')));
        }

        return $query->get()->keyBy('company_id');
    }

    private static function salesTotals(?Collection $rows, int $companyId): ?array
    {
        if ($rows === null) {
            return null;
        }

        $row = $rows->get($companyId);

        return ['bills' => (int) ($row->bills ?? 0), 'amount' => (float) ($row->amount ?? 0)];
    }
}
