<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A cash settlement event: the rider handed over cash for a set of bills.
 * Bills point back via pos_transactions.rider_settlement_id.
 *
 * Partial settlement (Task 525): total_amount = cash actually RECEIVED in this
 * event (not the face value of the bills). A partial receipt fully settles the
 * oldest bills it covers and leaves the remainder on the next bill's
 * rider_partial_paid. Extra audit columns:
 *   - outstanding_after: rider's whole-khata remaining right after this event.
 *   - allocation: JSON [{bill_id, amount, business_date}] of where the cash landed.
 *   - panel: 'pra' | 'fbr' — table is shared by both POS panels.
 */
class PosRiderSettlement extends Model
{
    protected $fillable = [
        'company_id', 'rider_id', 'settled_by', 'total_amount', 'bill_count', 'notes',
        'outstanding_after', 'allocation', 'panel',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'outstanding_after' => 'decimal:2',
        'allocation' => 'array',
    ];

    /** True when this event left part of a bill unpaid (allocation has one
     *  more entry than fully-settled bill_count → last entry was partial). */
    public function isPartial(): bool
    {
        $alloc = $this->allocation;
        return is_array($alloc) && count($alloc) > (int) $this->bill_count;
    }

    public function rider()
    {
        return $this->belongsTo(PosRider::class, 'rider_id');
    }

    public function settledBy()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function bills()
    {
        return $this->hasMany(PosTransaction::class, 'rider_settlement_id');
    }
}
