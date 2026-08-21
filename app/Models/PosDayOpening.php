<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Opening Cash Balance — one row per company per business date.
 * Recorded at day start (drawer float for change); consumed by day-close
 * cash reconciliation (auto-fills opening_float on the Z-report).
 */
class PosDayOpening extends Model
{
    protected $table = 'pos_day_openings';

    protected $fillable = [
        'company_id',
        // Per-branch day close (Task 1360): each branch counts its own drawer.
        // 0 = no branch (branch-less shop / pre-branch history) — never NULL,
        // see PosDayCloseReport::NO_BRANCH for why.
        'branch_id',
        'business_date',
        'opening_cash',
        'entered_by',
        'notes',
    ];

    protected $casts = [
        'business_date' => 'date',
        'opening_cash' => 'decimal:2',
    ];

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /**
     * The recorded opening for a company + date, or null. Task 1360: scoped to
     * the close's branch — schema-guarded so a box without the branch column
     * keeps its old company-wide lookup.
     */
    public static function forDate(int $companyId, string $date, ?int $branchId = null): ?self
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('pos_day_openings')) {
            return null;
        }
        return static::where('company_id', $companyId)
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('pos_day_openings', 'branch_id'),
                fn ($q) => $q->where('branch_id', PosDayCloseReport::branchKey($branchId)))
            ->whereDate('business_date', $date)
            ->first();
    }
}
