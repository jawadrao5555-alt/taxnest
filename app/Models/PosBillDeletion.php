<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quota ledger + audit trail for the one-by-one admin bill delete
 * (PosController::deleteTransaction, Task 1372).
 *
 * The monthly bill quota is counted off the rows still present in
 * pos_transactions, so hard-deleting a FINAL bill would hand the shop its slot
 * back — delete, re-bill, repeat, and a package limit means nothing.
 * PlanLimitService::canCreatePosBill adds these rows back into the count, the
 * same contract as pos_day_close_reports.deleted_final_count (day-close DELETE
 * policy) and pos_local_series_resets.deleted_final_count (clear archived
 * local bills).
 *
 * ONLY bills that actually consumed quota are recorded — provisionals, drafts
 * and returns never did, so they never appear here. sold_at (the deleted
 * bill's created_at) is the month anchor, matching the live count's basis.
 *
 * Never updated after insert.
 */
class PosBillDeletion extends Model
{
    protected $fillable = [
        'company_id', 'transaction_id', 'invoice_number',
        'sold_at', 'business_date', 'total_amount', 'deleted_by',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
        'business_date' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
