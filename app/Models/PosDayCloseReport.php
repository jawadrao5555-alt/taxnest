<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosDayCloseReport extends Model
{
    /**
     * Per-branch day close (Task 1360): branch_id 0 = "no branch" — a
     * branch-less shop, the pre-branch history, and every report frozen before
     * this feature. Never NULL, so the (company, branch, date) unique index
     * keeps working on MySQL and SQLite alike (both treat NULLs as distinct).
     */
    public const NO_BRANCH = 0;

    protected $fillable = [
        'company_id', 'branch_id', 'report_date', 'report_number',
        'total_invoices', 'pra_invoices', 'local_invoices', 'offline_invoices',
        'gross_sales', 'total_discount', 'net_sales', 'total_tax', 'total_amount',
        'cash_amount', 'card_amount', 'other_amount',
        'first_invoice_number', 'last_invoice_number',
        'first_invoice_time', 'last_invoice_time',
        'closed_by', 'notes', 'hash',
        'deleted_final_count', 'deleted_provisional_count', 'local_summary',
        // Per-stream figures (Task 660): PRA vs Local vs Exempt split with
        // payment buckets + exempt item detail, frozen at close time.
        'stream_summary',
        'opening_float', 'counted_cash', 'expected_cash', 'cash_variance',
        'rider_summary',
        // Returns audit snapshot (Task 682): per-return detail frozen at close
        // time — the wash may archive/delete local return rows afterwards.
        'returns_detail',
        // Return / credit-note netting (Task 570).
        'returns_count', 'returns_amount',
        // Wastage (Task 596): spoiled-goods return figures on the stored Z-report.
        'wastage_count', 'wastage_amount',
    ];

    protected $casts = [
        'report_date' => 'date',
        'first_invoice_time' => 'datetime',
        'last_invoice_time' => 'datetime',
        'local_summary' => 'array',
        'stream_summary' => 'array',
        'rider_summary' => 'array',
        'returns_detail' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Normalise an active-branch id (null = company-wide) to the stored key. */
    public static function branchKey(?int $branchId): int
    {
        return (int) ($branchId ?: self::NO_BRANCH);
    }

    /**
     * Reports for ONE close scope (Task 1360). Schema-guarded: a box whose
     * branch migration has not landed keeps its old company-wide behaviour
     * instead of exploding on an unknown column (prod drift convention).
     */
    public function scopeForBranch($query, ?int $branchId)
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_close_reports', 'branch_id')) {
            $query->where('branch_id', self::branchKey($branchId));
        }

        return $query;
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
