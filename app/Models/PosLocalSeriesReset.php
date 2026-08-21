<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit + quota ledger for the "clear archived local bills & restart the
 * L-series" action (Customize POS → Local Billing, Task 1358).
 *
 * One row per clear: what was removed, from which date range, by whom — and,
 * critically, how many reporting-OFF FINALS of the CURRENT month went with it.
 * PlanLimitService::canCreatePosBill adds that number back into the monthly
 * bill count so clearing can never be used to buy back quota (same contract as
 * pos_day_close_reports.deleted_final_count for the day-close delete policy).
 *
 * Never updated after insert.
 */
class PosLocalSeriesReset extends Model
{
    protected $fillable = [
        'company_id', 'reset_date',
        'deleted_final_count', 'deleted_provisional_count', 'total_deleted',
        'from_date', 'to_date', 'performed_by',
    ];

    protected $casts = [
        'reset_date' => 'date',
        'from_date' => 'date',
        'to_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
