<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
    public static function snapshotsForCompanies(Collection $companies): Collection
    {
        if ($companies->isEmpty()) {
            return collect();
        }

        $ids = $companies->pluck('id');
        $praIds = $companies->filter(fn ($company) => $company->product_type === 'pos')->pluck('id');
        $hasSales = Schema::hasColumns('pos_transactions', ['company_id', 'status', 'total_amount']);
        $sales = $hasSales && $praIds->isNotEmpty()
            ? DB::table('pos_transactions')->whereIn('company_id', $praIds)->where('status', 'completed')
                ->when(Schema::hasColumn('pos_transactions', 'is_archived'), fn ($query) => $query
                    ->where(fn ($visible) => $visible->where('is_archived', false)->orWhereNull('is_archived')))
                ->selectRaw('company_id, COUNT(*) as bills, COALESCE(SUM(total_amount), 0) as amount')
                ->groupBy('company_id')->get()->keyBy('company_id')
            : collect();

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

        return $companies->map(function (Company $company) use ($hasSales, $sales, $counts, $lastActivity) {
            $pra = $company->product_type === 'pos';
            $sale = $sales->get($company->id);
            $snapshot = new self([
                'company_id' => $company->id,
                'total_pos_transactions' => $pra && $hasSales ? (int) ($sale->bills ?? 0) : null,
                'total_sales_amount' => $pra && $hasSales ? (float) ($sale->amount ?? 0) : null,
                'last_activity_at' => $lastActivity[$company->id] ?? null,
            ]);
            foreach ($counts as $field => $values) {
                $snapshot->setAttribute($field, $values !== null && ($pra || $field === 'active_users')
                    ? (int) ($values[$company->id] ?? 0) : null);
            }
            $snapshot->setRelation('company', $company);

            return $snapshot;
        });
    }
}
